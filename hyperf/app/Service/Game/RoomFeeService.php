<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Dao\Member\FinancialLogDao;
use App\Dao\Member\MemberDao;
use App\Facades\Log;
use App\Service\BaseService;
use App\Service\Member\FinanceService;
use Hyperf\DbConnection\Db;
use function Hyperf\Support\make;

/**
 * 平台「支付平台费用」（与具体玩法无关）
 *
 * 各玩法一局打完后调用 {@see chargeAfterSettle()}：
 * - 费用规则读 room.rule（pay_mode / pay_amount）
 * - 与对局输赢分开记账；扣费失败不回滚输赢
 * - 约定 settle.rewards[] 含 playerId + delta（delta>0 视为赢家，供「赢家支付」）
 *
 * pay_amount：每局平台费用总额（非每人单价）
 * pay_mode:
 * 1 AA支付     — 总额在座玩家均分
 * 2 赢家支付   — 胜利方承担总额（多人赢则均分）
 * 3 我支付     — 房主承担本局总额
 */
class RoomFeeService extends BaseService
{
    public const PAY_AA = 1;

    public const PAY_WINNER = 2;

    public const PAY_OWNER = 3;

    private const SCALE = 2;

    public function __construct(
        MemberDao $memberDao,
        protected FinancialLogDao $logDao,
        protected RoomService $roomService,
        protected RoomMemberService $roomMemberService,
    ) {
        $this->dao = $memberDao;
    }

    /**
     * 局末统一入口：扣平台费，并把金额/余额写回 settle.rewards
     *
     * @param array{rewards?: list<array{playerId:int, delta:float, points?:float|null}>} $settle
     * @return array{
     *   settle: array,
     *   fee: array{charged:bool, payMode:int, payAmount:string, charges:list}
     * }
     */
    public function chargeAfterSettle(int $roomId, int $roundId, array $settle = []): array
    {
        $fee = $this->chargeAfterRound($roomId, $roundId, $settle);
        $settle['rewards'] = $this->mergeChargesIntoRewards(
            is_array($settle['rewards'] ?? null) ? $settle['rewards'] : [],
            $fee
        );

        return ['settle' => $settle, 'fee' => $fee];
    }

    /**
     * 将扣费结果合并进 rewards（platformFee / roomFee / points）
     *
     * @param list<array<string, mixed>> $rewards
     * @param array{charges?: list<array{playerId:int, amount:float, points:float}>} $feeResult
     * @return list<array<string, mixed>>
     */
    public function mergeChargesIntoRewards(array $rewards, array $feeResult): array
    {
        $feeByPid = [];
        foreach ($feeResult['charges'] ?? [] as $c) {
            $pid = (int) ($c['playerId'] ?? 0);
            if ($pid > 0) {
                $feeByPid[$pid] = $c;
            }
        }
        foreach ($rewards as &$rw) {
            $pid = (int) ($rw['playerId'] ?? 0);
            if ($pid > 0 && isset($feeByPid[$pid])) {
                $rw['platformFee'] = (float) ($feeByPid[$pid]['amount'] ?? 0);
                $rw['roomFee'] = $rw['platformFee'];
                $rw['points'] = (float) ($feeByPid[$pid]['points'] ?? $rw['points'] ?? 0);
            } else {
                $rw['platformFee'] = (float) ($rw['platformFee'] ?? 0);
                $rw['roomFee'] = (float) ($rw['roomFee'] ?? $rw['platformFee']);
            }
        }
        unset($rw);

        return $rewards;
    }

    /**
     * @param array{rewards?: list<array{playerId:int, delta:float, points?:float|null}>} $settle
     * @return array{
     *   charged: bool,
     *   payMode: int,
     *   payAmount: string,
     *   charges: list<array{playerId:int, amount:float, points:float}>
     * }
     */
    public function chargeAfterRound(int $roomId, int $roundId, array $settle = []): array
    {
        $empty = [
            'charged'   => false,
            'payMode'   => 0,
            'payAmount' => '0.00',
            'charges'   => [],
        ];

        if ($roomId <= 0 || $roundId <= 0) {
            return $empty;
        }

        $room = $this->roomService->getOne(['id' => $roomId], 'id,member_id,num,rule');
        if ($room === null) {
            return $empty;
        }

        $payMode = $this->roomService->resolvePayMode($room->rule ?? null);
        $unitFee = $this->roomService->resolvePayAmount($room->rule ?? null);
        if ($payMode < 1 || bccomp($unitFee, '0', self::SCALE) <= 0) {
            return $empty;
        }

        $members = $this->roomMemberService->getActiveInRoom($roomId, 'player_id,seat_no');
        $pids = [];
        foreach ($members as $m) {
            $pid = (int) ($m['player_id'] ?? 0);
            if ($pid > 0) {
                $pids[] = $pid;
            }
        }
        $pids = array_values(array_unique($pids));
        if ($pids === []) {
            return $empty;
        }

        $ownerPid = (int) ($room->member_id ?? 0);

        /** @var array<int,string> $dueByPid playerId => amount */
        $dueByPid = $this->buildDueMap($payMode, $unitFee, $pids, $ownerPid, $settle);
        if ($dueByPid === []) {
            return $empty;
        }

        // 幂等：order_sn 改为随机 helper 后，用会员+类型+唯一备注判断本局是否已扣。
        $pending = [];
        foreach ($dueByPid as $pid => $due) {
            $remark = $this->feeRemark($payMode, $roomId, $roundId, $unitFee);
            if (! $this->logDao->be([
                'member_id' => (int) $pid,
                'flow_type' => FinanceService::FLOW_ROOM_FEE,
                'remark'    => $remark,
            ])) {
                $pending[(int) $pid] = $due;
            }
        }
        if ($pending === []) {
            return $empty;
        }

        try {
            $charges = Db::transaction(function () use (
                $pending,
                $roomId,
                $roundId,
                $payMode,
                $unitFee
            ) {
                $lockIds = array_keys($pending);
                sort($lockIds);
                $now = date('Y-m-d H:i:s');
                $remark = $this->feeRemark($payMode, $roomId, $roundId, $unitFee);
                $out = [];

                foreach ($lockIds as $pid) {
                    if ($this->logDao->be([
                        'member_id' => $pid,
                        'flow_type' => FinanceService::FLOW_ROOM_FEE,
                        'remark'    => $remark,
                    ])) {
                        continue;
                    }
                    $due = $pending[$pid] ?? '0.00';
                    if (bccomp($due, '0', self::SCALE) <= 0) {
                        continue;
                    }
                    $row = $this->dao->lockForUpdate($pid, ['id', 'points']);
                    if ($row === null) {
                        continue;
                    }
                    $before = $this->normalize((string) ($row->points ?? '0'));
                    $pay = $this->bcMin($before, $due);
                    // 余额为 0 也写 0 元流水占位，避免反复重试
                    $after = bccomp($pay, '0', self::SCALE) > 0
                        ? bcsub($before, $pay, self::SCALE)
                        : $before;
                    if (bccomp($pay, '0', self::SCALE) > 0) {
                        $this->dao->update($pid, ['points' => $after]);
                    }
                    $this->logDao->create([
                        'member_id'            => $pid,
                        'change_amount'        => bccomp($pay, '0', self::SCALE) > 0 ? ('-' . $pay) : '0.00',
                        'before_amount'        => $before,
                        'after_amount'         => $after,
                        'flow_type'            => FinanceService::FLOW_ROOM_FEE,
                        'order_sn'             => build_order_sn('RF'),
                        'remark'               => $remark,
                        'before_frozen_amount' => '0.000',
                        'after_froze_amount'   => '0.000',
                        'create_at'            => $now,
                    ]);
                    $out[] = [
                        'playerId' => $pid,
                        'amount'   => (float) $pay,
                        'points'   => (float) $after,
                    ];
                }

                return $out;
            });
        } catch (\Throwable $e) {
            Log::error('平台费用扣除失败', [
                'room_id'  => $roomId,
                'round_id' => $roundId,
                'error'    => $e->getMessage(),
            ]);

            return $empty;
        }

        if ($charges === []) {
            return $empty;
        }

        return [
            'charged'   => true,
            'payMode'   => $payMode,
            'payAmount' => $unitFee,
            'charges'   => $charges,
        ];
    }

    private function feeRemark(int $payMode, int $roomId, int $roundId, string $unitFee): string
    {
        $modeText = match ($payMode) {
            self::PAY_AA => 'AA支付',
            self::PAY_WINNER => '赢家支付',
            self::PAY_OWNER => '房主支付',
            default => '平台费用',
        };

        return sprintf(
            '支付平台费用(%s) 房间%d 第%d局 本局%s元',
            $modeText,
            $roomId,
            $roundId,
            $unitFee
        );
    }

    /**
     * @param list<int> $pids
     * @param array{landlordWin?:bool,winSide?:string,rewards?:list<array{playerId:int,delta:float}>} $settle
     * @return array<int,string>
     */
    private function buildDueMap(
        int $payMode,
        string $unitFee,
        array $pids,
        int $ownerPid,
        array $settle,
    ): array {
        /** 规则里的 pay_amount = 本局平台费总额 */
        $roundTotal = $this->normalize($unitFee);
        if (bccomp($roundTotal, '0', self::SCALE) <= 0) {
            return [];
        }

        if ($payMode === self::PAY_AA) {
            $weights = [];
            foreach ($pids as $pid) {
                $weights[$pid] = 1;
            }

            return $this->splitAmong($weights, $roundTotal);
        }

        if ($payMode === self::PAY_OWNER) {
            $payer = $ownerPid > 0 ? $ownerPid : ($pids[0] ?? 0);
            if ($payer <= 0) {
                return [];
            }

            return [$payer => $roundTotal];
        }

        if ($payMode === self::PAY_WINNER) {
            $rewards = is_array($settle['rewards'] ?? null) ? $settle['rewards'] : [];
            $winners = [];
            foreach ($rewards as $r) {
                $pid = (int) ($r['playerId'] ?? 0);
                $delta = (float) ($r['delta'] ?? 0);
                if ($pid > 0 && $delta > 0) {
                    $winners[$pid] = 1;
                }
            }
            if ($winners === []) {
                $bestPid = 0;
                $bestDelta = null;
                foreach ($rewards as $r) {
                    $pid = (int) ($r['playerId'] ?? 0);
                    $delta = (float) ($r['delta'] ?? 0);
                    if ($pid > 0 && ($bestDelta === null || $delta > $bestDelta)) {
                        $bestDelta = $delta;
                        $bestPid = $pid;
                    }
                }
                if ($bestPid > 0) {
                    return [$bestPid => $roundTotal];
                }

                return [];
            }

            return $this->splitAmong($winners, $roundTotal);
        }

        return [];
    }

    /**
     * 按权重分摊总额（分单位整数运算，避免 float 如 1/3→0.49+0.49+0.52）
     *
     * @param array<int,int|float> $weights pid => weight
     * @return array<int,string>
     */
    private function splitAmong(array $weights, string $total): array
    {
        $pids = array_keys($weights);
        sort($pids);
        if ($pids === []) {
            return [];
        }

        $intWeights = [];
        $sumW = 0;
        foreach ($pids as $pid) {
            $w = max(0, (int) round((float) $weights[$pid]));
            $intWeights[$pid] = $w;
            $sumW += $w;
        }
        if ($sumW <= 0) {
            $first = (int) $pids[0];

            return $first > 0 ? [$first => $this->normalize($total)] : [];
        }

        // 转「分」整数：1.50 元 → 150 分
        $totalCents = (int) bcmul($this->normalize($total), '100', 0);
        $allocatedCents = 0;
        $map = [];
        $last = (int) $pids[count($pids) - 1];
        foreach ($pids as $pid) {
            $pid = (int) $pid;
            if ($pid === $last) {
                $cents = $totalCents - $allocatedCents;
            } else {
                $cents = intdiv($totalCents * $intWeights[$pid], $sumW);
                $allocatedCents += $cents;
            }
            $map[$pid] = bcdiv((string) $cents, '100', self::SCALE);
        }

        return $map;
    }

    private function normalize(string $v): string
    {
        return bcadd($v, '0', self::SCALE);
    }

    private function bcMin(string $a, string $b): string
    {
        return bccomp($a, $b, self::SCALE) <= 0 ? $a : $b;
    }
}
