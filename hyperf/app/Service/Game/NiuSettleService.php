<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Dao\Member\FinancialLogDao;
use App\Dao\Member\MemberDao;
use App\Facades\Log;
use App\Game\Niu\Card\NiuCard;
use App\Service\BaseService;
use App\Service\Member\FinanceService;
use Hyperf\DbConnection\Db;

/**
 * 斗牛结算：庄家与每位闲家独立对赌（不建独立结算表，流水走 financial_log）
 *
 * 应付额 = 闲家下注额(amount) × 抢庄倍数 × 胜方牌型倍数
 * - 平局：该对闲家不结算
 * - 输家余额不足：扣至 0，赢家按实付比例缩减（金币守恒）
 *
 * 与斗地主 GameSettleService 分离：对赌模型完全不同，禁止复用。
 */
class NiuSettleService extends BaseService
{
    private const SCALE = 2;

    public function __construct(
        MemberDao $memberDao,
        protected FinancialLogDao $logDao,
        protected RoomService $roomService,
        protected RoomMemberService $roomMemberService,
        protected GamePlayCardService $playCardService,
        protected NiuBetService $betService,
        protected GameRoundService $gameRoundService,
    ) {
        $this->dao = $memberDao;
    }

    /**
     * 执行一局斗牛结算（算牛 → 对赌 → 扣款写流水）
     *
     * @param int $bankerPid    庄家 player_id（来自 round.master_id）
     * @param int $grabMultiple 抢庄倍数（来自 round.multiple，至少为 1）
     * @return array{
     *     grabMultiple: int,
     *     hands: array<int, array{
     *         playerId:int, seatNo:int, isBanker:bool, cards:list<string>,
     *         niuType:int, niuLabel:string, niuMultiple:int, betMultiple:int,
     *         delta:float, points:float, win:bool
     *     }>,
     *     rewards: array<int, array{playerId:int, seatNo:int, isBanker:bool, delta:float, points:float}>
     * }
     */
    public function settle(int $roomId, int $roundId, int $bankerPid, int $grabMultiple): array
    {
        $empty = ['grabMultiple' => max(1, $grabMultiple), 'hands' => [], 'rewards' => []];
        if ($bankerPid <= 0 || $roundId <= 0) {
            return $empty;
        }

        $members = $this->roomMemberService->getActiveInRoom($roomId, 'player_id,seat_no');
        $seatMap = [];
        $pids = [];
        foreach ($members as $m) {
            $pid = (int) ($m['player_id'] ?? 0);
            if ($pid > 0) {
                $seatMap[$pid] = (int) ($m['seat_no'] ?? 0);
                $pids[] = $pid;
            }
        }
        if (! in_array($bankerPid, $pids, true)) {
            return $empty;
        }

        $grabMultiple = max(1, $grabMultiple);
        $room = $this->roomService->getOne(['id' => $roomId], 'id,rule');
        $niuMultipliers = $this->roomService->resolveNiuMultipliers($room->rule ?? null);
        $bottom = $this->normalize($this->roomService->resolveBottomPoints($room->rule ?? null));

        // ---------- 1. 加载闲家下注快照（庄家无行） ----------
        $bets = [];
        foreach ($this->betService->loadByRound($roundId) as $b) {
            $bets[(int) $b['player_id']] = $b;
        }

        // ---------- 2. 评估全员牌型 ----------
        $evals = [];
        $handsOut = [];
        foreach ($pids as $pid) {
            $cards = $this->playCardService->getLatestHand($roundId, $pid);
            $ev = NiuCard::evaluate($cards, $niuMultipliers);
            $evals[$pid] = $ev;
            $betMul = $pid === $bankerPid ? 0 : (int) ($bets[$pid]['multiple'] ?? 1);
            $handsOut[$pid] = [
                'playerId'    => $pid,
                'seatNo'      => $seatMap[$pid] ?? 0,
                'isBanker'    => $pid === $bankerPid,
                'cards'       => $cards,
                'niuType'     => $ev['type'],
                'niuLabel'    => $ev['label'],
                'niuMultiple' => $ev['multiple'],
                'betMultiple' => $pid === $bankerPid ? 0 : $betMul,
                'delta'       => 0.0,
                'points'      => 0.0,
                'win'         => false,
            ];
        }

        $bankerEval = $evals[$bankerPid];
        $deltas = [];
        foreach ($pids as $pid) {
            $deltas[$pid] = '0.00';
        }

        // ---------- 3. 庄 vs 每个闲家独立对赌，累加理论 deltas ----------
        foreach ($pids as $pid) {
            if ($pid === $bankerPid) {
                continue;
            }
            // 新流程无独立下注阶段：未找到下注快照时按 1 倍底分结算
            $betAmount = $this->normalize((string) ($bets[$pid]['amount'] ?? $bottom));
            if (bccomp($betAmount, '0', self::SCALE) <= 0) {
                continue;
            }
            $cmp = NiuCard::compare($evals[$pid], $bankerEval);
            if ($cmp === 0) {
                continue; // 平局：该对闲家不结算
            }
            // 应付 = 下注额 × 抢庄倍数 × 胜方牌型倍数
            $winMul = $cmp > 0
                ? (int) $evals[$pid]['multiple']
                : (int) $bankerEval['multiple'];
            $pay = $this->normalize(bcmul(
                bcmul($betAmount, (string) $grabMultiple, self::SCALE),
                (string) max(1, $winMul),
                self::SCALE
            ));
            if ($cmp > 0) {
                // 闲家赢：庄付闲
                $deltas[$bankerPid] = bcsub($deltas[$bankerPid], $pay, self::SCALE);
                $deltas[$pid] = bcadd($deltas[$pid], $pay, self::SCALE);
                $handsOut[$pid]['win'] = true;
            } else {
                // 庄赢：闲付庄
                $deltas[$pid] = bcsub($deltas[$pid], $pay, self::SCALE);
                $deltas[$bankerPid] = bcadd($deltas[$bankerPid], $pay, self::SCALE);
                $handsOut[$bankerPid]['win'] = true;
            }
        }

        // ---------- 4. 加锁扣款 + 写 financial_log ----------
        try {
            $rewards = Db::transaction(function () use (
                $pids,
                $deltas,
                $seatMap,
                $bankerPid,
                $roomId,
                $roundId,
                $grabMultiple,
                &$handsOut
            ) {
                // 按 player_id 升序加锁，避免并发结算死锁
                $lockIds = $pids;
                sort($lockIds);
                $points = [];
                foreach ($lockIds as $pid) {
                    $row = $this->dao->lockForUpdate($pid, ['id', 'points']);
                    $points[$pid] = $this->normalize((string) ($row->points ?? '0'));
                }

                // 负债方最多扣到 0；盈余方按比例缩到实付总和
                $adjusted = $this->adjustForBalance($deltas, $points);

                $now = date('Y-m-d H:i:s');
                $rewards = [];
                foreach ($pids as $pid) {
                    $delta = $adjusted[$pid];
                    $before = $points[$pid];
                    $after = bcadd($before, $delta, self::SCALE);
                    if (bccomp($after, '0', self::SCALE) < 0) {
                        $after = '0.00';
                    }

                    if (bccomp($delta, '0', self::SCALE) !== 0) {
                        $this->dao->update($pid, ['points' => $after]);
                        $isWinner = bccomp($delta, '0', self::SCALE) > 0;
                        $this->logDao->create([
                            'member_id'            => $pid,
                            'change_amount'        => $delta,
                            'before_amount'        => $before,
                            'after_amount'         => $after,
                            'flow_type'            => $isWinner ? FinanceService::FLOW_GAME_WIN : FinanceService::FLOW_GAME_LOSE,
                            'order_sn'             => build_order_sn('NIU'),
                            'remark'               => sprintf(
                                '斗牛结算 房间%d 局%d %s x%d倍',
                                $roomId,
                                $roundId,
                                $pid === $bankerPid ? '庄' : '闲',
                                $grabMultiple
                            ),
                            'before_frozen_amount' => '0.000',
                            'after_froze_amount'   => '0.000',
                            'create_at'            => $now,
                        ]);
                    }

                    $handsOut[$pid]['delta'] = (float) $delta;
                    $handsOut[$pid]['points'] = (float) $after;
                    $rewards[] = [
                        'playerId' => $pid,
                        'seatNo'   => $seatMap[$pid] ?? 0,
                        'isBanker' => $pid === $bankerPid,
                        'delta'    => (float) $delta,
                        'points'   => (float) $after,
                    ];
                }

                return $rewards;
            });
        } catch (\Throwable $e) {
            Log::error('斗牛结算失败', [
                'room_id'  => $roomId,
                'round_id' => $roundId,
                'banker'   => $bankerPid,
                'error'    => $e->getMessage(),
                'trace'    => $e->getTraceAsString(),
            ]);

            return ['grabMultiple' => $grabMultiple, 'hands' => array_values($handsOut), 'rewards' => []];
        }

        return [
            'grabMultiple' => $grabMultiple,
            'hands'        => array_values($handsOut),
            'rewards'      => $rewards,
        ];
    }

    /**
     * 按余额裁剪理论输赢，保证「实收 = 实付」、金币不出现负数
     *
     * 步骤：
     * 1. 每个输家实际支付 = min(|理论应付|, 当前余额)
     * 2. 若实付总和 < 理论应付总和：赢家按各自理论赢分占比，瓜分实付总和
     * 3. 若无人亏损或实付刚好够：直接返回（赢家仍用理论值）
     *
     * @param array<int, string> $deltas 理论有符号变动（正=赢，负=输）
     * @param array<int, string> $points 加锁后的当前余额
     * @return array<int, string> 裁剪后的实际变动
     */
    private function adjustForBalance(array $deltas, array $points): array
    {
        $adjusted = $deltas;
        $totalNeed = '0.00'; // 理论应付总额
        $totalCan = '0.00';  // 实际能掏出的总额

        // —— 步骤 1：裁剪每个输家 ——
        foreach ($deltas as $pid => $delta) {
            if (bccomp($delta, '0', self::SCALE) < 0) {
                $need = $this->normalize(ltrim($delta, '-'));
                $can = $this->bcMin($points[$pid], $need);
                $totalNeed = bcadd($totalNeed, $need, self::SCALE);
                $totalCan = bcadd($totalCan, $can, self::SCALE);
                $adjusted[$pid] = '-' . $can;
            }
        }

        // 没有输家，或每个输家都掏得起 → 赢家不用缩减
        if (bccomp($totalNeed, '0', self::SCALE) <= 0
            || bccomp($totalNeed, $totalCan, self::SCALE) === 0) {
            return $adjusted;
        }

        // —— 步骤 2：按理论赢分比例分配实付总额 ——
        $posSum = '0.00';
        foreach ($deltas as $delta) {
            if (bccomp($delta, '0', self::SCALE) > 0) {
                $posSum = bcadd($posSum, $delta, self::SCALE);
            }
        }
        foreach ($deltas as $pid => $delta) {
            if (bccomp($delta, '0', self::SCALE) > 0) {
                if (bccomp($posSum, '0', self::SCALE) <= 0) {
                    $adjusted[$pid] = '0.00';
                } else {
                    $share = bcmul($totalCan, bcdiv($delta, $posSum, 8), self::SCALE);
                    $adjusted[$pid] = $this->normalize($share);
                }
            } elseif (bccomp($delta, '0', self::SCALE) === 0) {
                $adjusted[$pid] = '0.00';
            }
        }

        return $adjusted;
    }

    private function bcMin(string $a, string $b): string
    {
        return bccomp($a, $b, self::SCALE) <= 0 ? $this->normalize($a) : $this->normalize($b);
    }

    private function normalize(string $amount): string
    {
        return bcadd($amount, '0', self::SCALE);
    }
}
