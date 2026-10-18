<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Dao\Member\FinancialLogDao;
use App\Dao\Member\MemberDao;
use App\Facades\Log;
use App\Game\Pdk\PdkConsts;
use App\Game\Pdk\RoundState;
use App\Service\BaseService;
use App\Service\Member\FinanceService;
use Hyperf\DbConnection\Db;
use function Hyperf\Support\make;

/**
 * 跑得快结算：
 * 1) 最大牌（四张炸弹 / 三个 A）打出时当场结算：其余两人分别各付固定炸弹分（房间 rule.bomb_multiple，默认 10 分）
 * 2) 局末：仅按剩余张数结算（全关 16 张再 ×2）；炸弹分已即时入账，不再重复
 */
class PdkSettleService extends BaseService
{
    private const SCALE = 2;

    public function __construct(
        MemberDao $memberDao,
        protected FinancialLogDao $logDao,
        protected RoomService $roomService,
        protected RoomMemberService $roomMemberService,
        protected GamePlayCardService $playCardService,
    ) {
        $this->dao = $memberDao;
    }

    /**
     * 最大牌（炸弹 / 三个 A）当场结算：其余两人分别各付固定炸弹分；金币当场入账。
     *
     * @return array{
     *   unit: float,
     *   bombMultiple: int,
     *   bombUnit: float,
     *   bomberSeat: int,
     *   bomberId: int,
     *   rewards: list<array{playerId:int,seatNo:int,delta:float,points:float,isBomber:bool}>
     * }|null
     */
    public function settleBombNow(int $roomId, int $roundId, int $bomberPid): ?array
    {
        if ($roomId <= 0 || $roundId <= 0 || $bomberPid <= 0) {
            return null;
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
        if (! in_array($bomberPid, $pids, true) || count($pids) < 2) {
            return null;
        }

        $room = $this->roomService->getOne(['id' => $roomId], 'id,rule');
        $bottom = $this->normalize($this->roomService->resolveBottomPoints($room->rule ?? null));
        $bombScore = RoundState::bombScoreFromRule($room->rule ?? null);
        // 单边应付：固定炸弹分（房间创建时设定，如 10 分）
        $bombUnit = $this->normalize((string) $bombScore);

        $round = make(GameRoundService::class)->getOne(['id' => $roundId], 'id,extra');
        $state = RoundState::decode($round->extra ?? null);
        $surrendered = is_array($state['surrendered'] ?? null) ? $state['surrendered'] : [];

        // 两边分别给：每个对手独立付给出发者 bombUnit
        $deltas = [];
        foreach ($pids as $pid) {
            $deltas[$pid] = '0.00';
        }
        foreach ($pids as $pid) {
            if ($pid === $bomberPid) {
                continue;
            }
            $pay = $bombUnit;
            $deltas[$pid] = bcsub($deltas[$pid], $pay, self::SCALE);
            $deltas[$bomberPid] = bcadd($deltas[$bomberPid], $pay, self::SCALE);
        }

        try {
            $rewards = Db::transaction(function () use (
                $pids,
                $deltas,
                $seatMap,
                $bomberPid,
                $roomId,
                $roundId,
                $bottom,
                $bombUnit,
                $bombScore
            ) {
                $lockIds = $pids;
                sort($lockIds);
                $points = [];
                foreach ($lockIds as $pid) {
                    $row = $this->dao->lockForUpdate($pid, ['id', 'points']);
                    $points[$pid] = $this->normalize((string) ($row->points ?? '0'));
                }

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
                        $isWin = bccomp($delta, '0', self::SCALE) > 0;
                        $this->logDao->create([
                            'member_id'            => $pid,
                            'change_amount'        => $delta,
                            'before_amount'        => $before,
                            'after_amount'         => $after,
                            'flow_type'            => $isWin ? FinanceService::FLOW_GAME_WIN : FinanceService::FLOW_GAME_LOSE,
                            'order_sn'             => build_order_sn('PDKB'),
                            'remark'               => sprintf(
                                '跑得快最大牌即时结算 房间%d 局%d 炸弹分%s（两边分别给付）',
                                $roomId,
                                $roundId,
                                $bombUnit
                            ),
                            'before_frozen_amount' => '0.000',
                            'after_froze_amount'   => '0.000',
                            'create_at'            => $now,
                        ]);
                    }

                    $rewards[] = [
                        'playerId' => $pid,
                        'seatNo'   => $seatMap[$pid] ?? 0,
                        'delta'    => (float) $delta,
                        'points'   => (float) $after,
                        'isBomber' => $pid === $bomberPid,
                    ];
                }

                return $rewards;
            });
        } catch (\Throwable $e) {
            Log::error('跑得快炸弹即时结算失败', [
                'room_id'  => $roomId,
                'round_id' => $roundId,
                'bomber'   => $bomberPid,
                'error'    => $e->getMessage(),
            ]);

            return null;
        }

        return [
            'unit'         => (float) $bottom,
            'bombMultiple' => $bombScore,
            'bombUnit'     => (float) $bombUnit,
            'bomberSeat'   => $seatMap[$bomberPid] ?? 0,
            'bomberId'     => $bomberPid,
            'rewards'      => $rewards,
        ];
    }

    /**
     * 局末仅按剩余张数结算（炸弹已即时结算，不再计入）
     *
     * @return array{
     *   unit: float,
     *   bombUnit: float,
     *   bombMultiple: int,
     *   bombCount: int,
     *   rewards: list<array<string, mixed>>
     * }
     */
    public function settle(int $roomId, int $roundId, int $winnerPid): array
    {
        $empty = [
            'unit'         => 0.0,
            'bombUnit'     => 0.0,
            'bombMultiple' => PdkConsts::BOMB_SCORE_MULTIPLE,
            'bombCount'    => 0,
            'rewards'      => [],
        ];
        if ($winnerPid <= 0 || $roundId <= 0) {
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
        if (! in_array($winnerPid, $pids, true)) {
            return $empty;
        }

        $room = $this->roomService->getOne(['id' => $roomId], 'id,rule');
        $bottom = $this->normalize($this->roomService->resolveBottomPoints($room->rule ?? null));
        $bombScore = RoundState::bombScoreFromRule($room->rule ?? null);
        $bombUnit = $this->normalize((string) $bombScore);

        $round = make(GameRoundService::class)->getOne(['id' => $roundId], 'id,extra');
        $state = RoundState::decode($round->extra ?? null);
        $bombCount = max(0, (int) ($state['bombCount'] ?? count($state['bombs'] ?? [])));
        $surrendered = is_array($state['surrendered'] ?? null) ? $state['surrendered'] : [];

        $remains = [];
        $cardDeltas = [];
        $ownPays = [];
        foreach ($pids as $pid) {
            $hand = $this->playCardService->getLatestHand($roundId, $pid);
            $remains[$pid] = $pid === $winnerPid ? 0 : count($hand);
            $cardDeltas[$pid] = '0.00';
            $ownPays[$pid] = '0.00';
        }

        // 仅剩余张数；炸弹分已在出炸时即时结算
        foreach ($pids as $pid) {
            if ($pid === $winnerPid) {
                continue;
            }
            $seat = (int) ($seatMap[$pid] ?? -1);
            if (\App\Game\Pdk\Play\SurrenderHelper::isSurrendered($seat, $surrendered)) {
                continue;
            }
            $left = $remains[$pid];
            if ($left <= 0) {
                continue;
            }
            $factor = $left >= PdkConsts::HAND_SIZE ? 2 : 1;
            $pay = $this->normalize(bcmul(
                bcmul($bottom, (string) $left, self::SCALE),
                (string) $factor,
                self::SCALE
            ));
            $ownPays[$pid] = $pay;
            $cardDeltas[$pid] = bcsub($cardDeltas[$pid], $pay, self::SCALE);
            $cardDeltas[$winnerPid] = bcadd($cardDeltas[$winnerPid], $pay, self::SCALE);
        }

        // 放走包赔：下家剩 1 张时出了非最大单，且该下家赢了。
        // 其他输家的剩牌分改由放走者支付，赢家实收不变。
        $fangzouState = is_array($state['fangzou'] ?? null) ? $state['fangzou'] : [];
        $fangzouBy = (int) ($fangzouState['by'] ?? 0);
        $fangzouNext = (int) ($fangzouState['next'] ?? 0);
        $fangzouOn = $fangzouBy > 0
            && $fangzouNext === $winnerPid
            && $fangzouBy !== $winnerPid
            && in_array($fangzouBy, $pids, true);
        $fangzouCovers = [];
        if ($fangzouOn) {
            foreach ($pids as $pid) {
                if ($pid === $winnerPid || $pid === $fangzouBy) {
                    continue;
                }
                $pay = $ownPays[$pid] ?? '0.00';
                if (bccomp($pay, '0', self::SCALE) <= 0) {
                    continue;
                }
                $fangzouCovers[] = [
                    'playerId' => $pid,
                    'seatNo'   => (int) ($seatMap[$pid] ?? 0),
                    'remain'   => (int) ($remains[$pid] ?? 0),
                    'pay'      => (float) $pay,
                ];
                $cardDeltas[$pid] = '0.00';
                $cardDeltas[$fangzouBy] = bcsub($cardDeltas[$fangzouBy], $pay, self::SCALE);
            }
        }
        $fangzouPayload = $fangzouOn && $fangzouCovers !== [] ? [
            'payerSeat' => (int) ($seatMap[$fangzouBy] ?? 0),
            'payerId'   => $fangzouBy,
            'covers'    => $fangzouCovers,
        ] : null;

        $deltas = $cardDeltas;

        try {
            $rewards = Db::transaction(function () use (
                $pids,
                $deltas,
                $cardDeltas,
                $remains,
                $seatMap,
                $winnerPid,
                $roomId,
                $roundId,
                $bottom,
                $bombCount,
                $state,
                $fangzouPayload,
                $fangzouBy,
                $ownPays
            ) {
                $lockIds = $pids;
                sort($lockIds);
                $points = [];
                foreach ($lockIds as $pid) {
                    $row = $this->dao->lockForUpdate($pid, ['id', 'points']);
                    $points[$pid] = $this->normalize((string) ($row->points ?? '0'));
                }

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
                        $isWin = bccomp($delta, '0', self::SCALE) > 0;
                        $this->logDao->create([
                            'member_id'            => $pid,
                            'change_amount'        => $delta,
                            'before_amount'        => $before,
                            'after_amount'         => $after,
                            'flow_type'            => $isWin ? FinanceService::FLOW_GAME_WIN : FinanceService::FLOW_GAME_LOSE,
                            'order_sn'             => build_order_sn('PDK'),
                            'remark'               => sprintf(
                                '跑得快局末结算 房间%d 局%d 底分%s 剩%d%s（炸弹已即时结算，共%d炸）',
                                $roomId,
                                $roundId,
                                $bottom,
                                $remains[$pid],
                                $fangzouPayload !== null && $pid === $fangzouBy ? ' 放走包赔' : '',
                                $bombCount
                            ),
                            'before_frozen_amount' => '0.000',
                            'after_froze_amount'   => '0.000',
                            'create_at'            => $now,
                        ]);
                    }

                    $left = $remains[$pid];
                    $seatNo = $seatMap[$pid] ?? 0;
                    $bombScores = is_array($state['bombScores'] ?? null) ? $state['bombScores'] : [];
                    $bombDelta = $this->normalize((string) ($bombScores[(string) $pid] ?? '0'));
                    $rewards[] = [
                        'playerId'     => $pid,
                        'seatNo'       => $seatNo,
                        'isWinner'     => $pid === $winnerPid,
                        'remain'       => $left,
                        'shutout'      => $left >= PdkConsts::HAND_SIZE,
                        'bombCount'    => 0,
                        'playerDouble' => 1,
                        'cardDelta'    => (float) $cardDeltas[$pid],
                        'ownPay'       => (float) ($ownPays[$pid] ?? 0),
                        'fangzouPayer' => $fangzouPayload !== null && $pid === $fangzouBy,
                        'fangzouCovered'=> $fangzouPayload !== null && $pid !== $fangzouBy && $pid !== $winnerPid && bccomp((string) ($ownPays[$pid] ?? '0'), '0', self::SCALE) > 0,
                        'bombDelta'    => (float) $bombDelta,
                        'roundDelta'   => (float) bcadd($cardDeltas[$pid], $bombDelta, self::SCALE),
                        'delta'        => (float) $delta,
                        'points'       => (float) $after,
                    ];
                }

                return $rewards;
            });
        } catch (\Throwable $e) {
            Log::error('跑得快结算失败', [
                'room_id'  => $roomId,
                'round_id' => $roundId,
                'winner'   => $winnerPid,
                'error'    => $e->getMessage(),
                'trace'    => $e->getTraceAsString(),
            ]);

            return $empty;
        }

        return [
            'unit'         => (float) $bottom,
            'bombUnit'     => (float) $bombUnit,
            'bombMultiple' => $bombScore,
            'bombCount'    => $bombCount,
            'fangzou'      => $fangzouPayload,
            'rewards'      => $rewards,
        ];
    }

    /**
     * @param array<int, string> $deltas
     * @param array<int, string> $points
     * @return array<int, string>
     */
    private function adjustForBalance(array $deltas, array $points): array
    {
        $adjusted = $deltas;
        $totalNeed = '0.00';
        $totalCan = '0.00';

        foreach ($deltas as $pid => $delta) {
            if (bccomp($delta, '0', self::SCALE) < 0) {
                $need = $this->normalize(ltrim($delta, '-'));
                $can = $this->bcMin($points[$pid], $need);
                $totalNeed = bcadd($totalNeed, $need, self::SCALE);
                $totalCan = bcadd($totalCan, $can, self::SCALE);
                $adjusted[$pid] = '-' . $can;
            }
        }

        if (bccomp($totalNeed, '0', self::SCALE) <= 0
            || bccomp($totalNeed, $totalCan, self::SCALE) === 0) {
            return $adjusted;
        }

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
