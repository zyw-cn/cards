<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Dao\Member\FinancialLogDao;
use App\Dao\Member\MemberDao;
use App\Facades\Log;
use App\Game\Dou\Card\LandlordCard;
use App\Game\Dou\RoundState;
use App\Game\Dou\Rule\BidRule;
use App\Game\Lz\Card\LaiziCard;
use App\Service\BaseService;
use App\Service\Member\FinanceService;
use Hyperf\DbConnection\Db;
use function Hyperf\Support\make;

/**
 * 斗地主一局结算（对齐 QQ 欢乐斗地主加倍）：
 * - 对每个农民：单位分 = 底分 × 叫抢倍数 × 地主加倍 × 该农民加倍 × 2^炸弹数 × 春天倍数
 * - 地主赢：两农民各付；农民赢：地主对两家各付
 * - 春天：地主胜且两农民未出过牌；反春天：农民胜且地主仅出过一手 → 全场 ×2
 * - 输家金币不足时扣至 0；赢家仅实收对家实际支付部分
 */
class GameSettleService extends BaseService
{
    /** member.points 精度 decimal(x,2) */
    private const SCALE = 2;

    public function __construct(
        MemberDao $memberDao,
        protected FinancialLogDao $logDao,
        protected RoomService $roomService,
        protected RoomMemberService $roomMemberService,
        protected LandLordBidService $bidService,
        protected GamePlayCardService $playCardService,
    ) {
        $this->dao = $memberDao;
    }

    /**
     * 执行结算并落库金币变动 + 流水。
     *
     * @return array{
     *     unit: float,
     *     multiple: int,
     *     bidMultiple: int,
     *     bombCount: int,
     *     spring: bool,
     *     springType: string,
     *     winSide: string,
     *     landlordWin: bool,
     *     farmerDoubles: array<string,int>,
     *     hands: list<array{seatNo:int, playerId:int, cards:list<string>, handCount:int}>,
     *     remainCards: array<string, list<string>>,
     *     rewards: array<int, array{playerId:int, seatNo:int, isLandlord:bool, delta:float, points:float, farmerDouble:int, cards?:list<string>, handCount?:int}>
     * }
     */
    public function settle(int $roomId, int $roundId, int $masterPid, int $winnerPid): array
    {
        $empty = [
            'unit'          => 0.0,
            'multiple'      => 1,
            'bidMultiple'   => 1,
            'bombCount'     => 0,
            'spring'        => false,
            'springType'    => 'none',
            'winSide'       => 'farmer',
            'landlordWin'   => false,
            'farmerDoubles' => [],
            'hands'         => [],
            'remainCards'   => [],
            'rewards'       => [],
        ];

        if ($masterPid <= 0) {
            return $empty;
        }

        $members = $this->roomMemberService->getActiveInRoom(
            $roomId,
            'player_id,seat_no'
        );
        $seatMap = [];
        $pids = [];
        foreach ($members as $m) {
            $pid = (int) ($m['player_id'] ?? 0);
            if ($pid > 0) {
                $seatMap[$pid] = (int) ($m['seat_no'] ?? 0);
                $pids[] = $pid;
            }
        }
        $farmerPids = array_values(array_filter($pids, static fn (int $p): bool => $p !== $masterPid));
        if (! in_array($masterPid, $pids, true) || $farmerPids === []) {
            return $empty;
        }

        $room = $this->roomService->getOne(['id' => $roomId], 'id,rule');
        $bottom = $this->normalize($this->roomService->resolveBottomPoints($room->rule ?? null));
        $round = make(GameRoundService::class)->getOne(['id' => $roundId], 'id,extra');
        $state = RoundState::decode($round->extra ?? null);
        $farmerDoublesState = is_array($state['farmerDoubles'] ?? null) ? $state['farmerDoubles'] : [];

        $bidMultiple = max(1, (int) ($state['landlordMultiple'] ?? 0));
        if ($bidMultiple < 1) {
            $bidMultiple = max(1, (int) ($this->bidService->getMax(['game_round_id' => $roundId], 'multiple') ?: 1));
        }

        // 优先用出牌阶段累计的 bombCount（含癞子软炸）；历史回扫作兜底
        $bombCount = max(
            0,
            (int) ($state['bombCount'] ?? 0),
            $this->countBombs($roundId)
        );
        $bombFactor = 2 ** $bombCount;
        $landlordWin = $winnerPid === $masterPid;
        $springInfo = $this->detectSpring($roundId, $masterPid, $landlordWin, $farmerPids);
        $springFactor = (int) $springInfo['springFactor'];
        // 公共桌面倍数仅叫抢×炸弹×春天；个人加倍在对局单位分里两两再乘
        $displayMultiple = BidRule::tableMultiple($bidMultiple, $bombCount, $springFactor);

        $landlordSeat = (int) ($seatMap[$masterPid] ?? -1);
        $landlordDoubleFactor = BidRule::settleDoubleOf(
            (int) ($farmerDoublesState[(string) $landlordSeat] ?? 0)
        );

        // 每位农民独立单位分
        $unitByFarmer = [];
        $doubleByPid = [];
        foreach ($farmerPids as $fp) {
            $seat = (int) ($seatMap[$fp] ?? -1);
            $farmerDouble = BidRule::settleDoubleOf((int) ($farmerDoublesState[(string) $seat] ?? 0));
            $doubleByPid[$fp] = $farmerDouble;
            $unitByFarmer[$fp] = $this->normalize(
                bcmul(
                    $bottom,
                    (string) ($bidMultiple * $landlordDoubleFactor * $farmerDouble * $bombFactor * $springFactor),
                    self::SCALE
                )
            );
        }

        $anyUnit = '0.00';
        foreach ($unitByFarmer as $u) {
            if (bccomp($u, $anyUnit, self::SCALE) > 0) {
                $anyUnit = $u;
            }
        }

        $meta = [
            'unit'          => (float) $anyUnit,
            'multiple'      => $displayMultiple,
            'bidMultiple'   => $bidMultiple,
            'bombCount'     => $bombCount,
            'spring'        => (bool) $springInfo['spring'],
            'springType'    => (string) $springInfo['springType'],
            'winSide'       => $landlordWin ? 'landlord' : 'farmer',
            'landlordWin'   => $landlordWin,
            'farmerDoubles' => $farmerDoublesState,
            'rewards'       => [],
            'hands'         => [],
            'remainCards'   => [],
        ];

        if (bccomp($anyUnit, '0', self::SCALE) <= 0) {
            return $this->withRemainHands($meta, $roundId, $pids, $seatMap);
        }

        $multiple = $displayMultiple;

        try {
            $rewards = Db::transaction(function () use (
                $masterPid,
                $farmerPids,
                $pids,
                $unitByFarmer,
                $landlordWin,
                $seatMap,
                $roomId,
                $roundId,
                $multiple,
                $winnerPid,
                $room,
                $doubleByPid,
                $landlordDoubleFactor
            ) {
                $lockIds = $pids;
                sort($lockIds);
                $points = [];
                foreach ($lockIds as $pid) {
                    $row = $this->dao->lockForUpdate($pid, ['id', 'points']);
                    $points[$pid] = $this->normalize((string) ($row->points ?? '0'));
                }

                $deltas = [];
                foreach ($pids as $pid) {
                    $deltas[$pid] = '0.00';
                }

                if ($landlordWin) {
                    $gain = '0.00';
                    foreach ($farmerPids as $fp) {
                        $unit = $unitByFarmer[$fp] ?? '0.00';
                        $pay = $this->bcMin($points[$fp], $unit);
                        $deltas[$fp] = '-' . $pay;
                        $gain = bcadd($gain, $pay, self::SCALE);
                    }
                    $deltas[$masterPid] = $gain;
                } else {
                    $remaining = $points[$masterPid];
                    $paidTotal = '0.00';
                    foreach ($farmerPids as $fp) {
                        $unit = $unitByFarmer[$fp] ?? '0.00';
                        $pay = $this->bcMin($remaining, $unit);
                        $deltas[$fp] = $pay;
                        $remaining = bcsub($remaining, $pay, self::SCALE);
                        $paidTotal = bcadd($paidTotal, $pay, self::SCALE);
                    }
                    $deltas[$masterPid] = '-' . $paidTotal;
                }

                $rule = json_decode((string) ($room->rule ?? ''), true);
                if (! is_array($rule)) {
                    $rule = [];
                }
                $rule['last_winner_pid'] = $winnerPid;
                $this->roomService->update($roomId, [
                    'rule' => json_encode($rule, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);

                $now = date('Y-m-d H:i:s');
                $rewards = [];
                foreach ($pids as $pid) {
                    $delta = $deltas[$pid];
                    $before = $points[$pid];
                    $after = bcadd($before, $delta, self::SCALE);
                    if (bccomp($after, '0', self::SCALE) < 0) {
                        $after = '0.00';
                    }

                    $isWinner = bccomp($delta, '0', self::SCALE) > 0;

                    if (bccomp($delta, '0', self::SCALE) !== 0) {
                        $this->dao->update($pid, ['points' => $after]);
                        $this->logDao->create([
                            'member_id'            => $pid,
                            'change_amount'        => $delta,
                            'before_amount'        => $before,
                            'after_amount'         => $after,
                            'flow_type'            => $isWinner ? FinanceService::FLOW_GAME_WIN : FinanceService::FLOW_GAME_LOSE,
                            'order_sn'             => build_order_sn('DDZ'),
                            'remark'               => sprintf(
                                '斗地主结算 房间%d 第%d局 %s x%d倍',
                                $roomId,
                                $roundId,
                                $pid === $masterPid ? '地主' : '农民',
                                $multiple
                            ),
                            'before_frozen_amount' => '0.000',
                            'after_froze_amount'   => '0.000',
                            'create_at'            => $now,
                        ]);
                    }

                    $remainHand = $this->playCardService->getLatestHand($roundId, $pid);
                    $rewards[] = [
                        'playerId'     => $pid,
                        'seatNo'       => $seatMap[$pid] ?? 0,
                        'isLandlord'   => $pid === $masterPid,
                        'delta'        => (float) $delta,
                        'points'       => (float) $after,
                        'farmerDouble' => $pid === $masterPid ? $landlordDoubleFactor : (int) ($doubleByPid[$pid] ?? 1),
                        'cards'        => $remainHand,
                        'handCount'    => count($remainHand),
                    ];
                }

                return $rewards;
            });
        } catch (\Throwable $e) {
            Log::error('游戏结算失败', [
                'room_id'   => $roomId,
                'round_id'  => $roundId,
                'master'    => $masterPid,
                'winner'    => $winnerPid,
                'error'     => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);
            $meta['rewards'] = [];

            return $this->withRemainHands($meta, $roundId, $pids, $seatMap);
        }

        $meta['rewards'] = $rewards;

        return $this->withRemainHands($meta, $roundId, $pids, $seatMap);
    }

    /**
     * 春天 / 反春天（对齐欢乐斗地主）
     * - 春天：地主获胜，且两农民整局未打出过牌（pass 不计）
     * - 反春天：农民获胜，且地主整局只打出过一手牌
     *
     * @param list<int> $farmerPids
     * @return array{spring:bool, springType:string, springFactor:int}
     */
    private function detectSpring(int $roundId, int $masterPid, bool $landlordWin, array $farmerPids): array
    {
        $rows = $this->playCardService->getAll(
            ['game_round_id' => $roundId],
            'seq,member_id,cards'
        );
        $playCount = [];
        foreach ($rows as $r) {
            if ((int) ($r['seq'] ?? 0) <= 0) {
                continue;
            }
            $raw = (string) ($r['cards'] ?? '');
            if ($raw === '') {
                continue;
            }
            $cards = GamePlayCardService::decodeCards($raw);
            if ($cards === []) {
                continue;
            }
            $pid = (int) ($r['member_id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $playCount[$pid] = ($playCount[$pid] ?? 0) + 1;
        }

        if ($landlordWin) {
            $spring = true;
            foreach ($farmerPids as $fp) {
                if (($playCount[$fp] ?? 0) > 0) {
                    $spring = false;
                    break;
                }
            }
            if ($spring) {
                return ['spring' => true, 'springType' => 'spring', 'springFactor' => 2];
            }
        } elseif (($playCount[$masterPid] ?? 0) === 1) {
            return ['spring' => true, 'springType' => 'anti', 'springFactor' => 2];
        }

        return ['spring' => false, 'springType' => 'none', 'springFactor' => 1];
    }

    /**
     * @param list<int> $pids
     * @param array<int,int> $seatMap
     * @return array<string, mixed>
     */
    private function withRemainHands(array $meta, int $roundId, array $pids, array $seatMap): array
    {
        $rewards = is_array($meta['rewards'] ?? null) ? $meta['rewards'] : [];
        $byPid = [];
        foreach ($rewards as $i => $r) {
            $byPid[(int) ($r['playerId'] ?? 0)] = $i;
        }

        $hands = [];
        $remainCards = [];
        foreach ($pids as $pid) {
            $pid = (int) $pid;
            if ($pid <= 0) {
                continue;
            }
            $idx = $byPid[$pid] ?? null;
            $existing = ($idx !== null && is_array($rewards[$idx]['cards'] ?? null))
                ? $rewards[$idx]['cards']
                : null;
            $cards = $existing !== null
                ? $existing
                : $this->playCardService->getLatestHand($roundId, $pid);
            $seat = (int) ($seatMap[$pid] ?? 0);
            $hands[] = [
                'seatNo'    => $seat,
                'playerId'  => $pid,
                'cards'     => $cards,
                'handCount' => count($cards),
            ];
            $remainCards[(string) $seat] = $cards;
            if ($idx !== null) {
                $rewards[$idx]['cards'] = $cards;
                $rewards[$idx]['handCount'] = count($cards);
            }
        }

        $meta['rewards'] = $rewards;
        $meta['hands'] = $hands;
        $meta['remainCards'] = $remainCards;

        return $meta;
    }

    /**
     * 从出牌记录回扫炸弹 / 王炸次数（兜底；癞子软炸以 LaiziCard 再认一次）
     */
    public function countBombs(int $roundId): int
    {
        $rows = $this->playCardService->getAll(['game_round_id' => $roundId], 'cards');
        $count = 0;
        foreach ($rows as $r) {
            $raw = (string) ($r['cards'] ?? '');
            if ($raw === '') {
                continue;
            }
            $cards = GamePlayCardService::decodeCards($raw);
            if ($cards === []) {
                continue;
            }
            [$type] = LandlordCard::analyzeCards($cards);
            if ($type === LandlordCard::CARD_TYPE_BOMB || $type === LandlordCard::CARD_TYPE_ROCKET) {
                $count++;
                continue;
            }
            // 癞子软炸：LandlordCard 认不出（W55 点数不同），再用 LaiziCard
            [$lzType] = LaiziCard::analyzeCards($cards);
            if ($lzType === LaiziCard::CARD_TYPE_BOMB || $lzType === LaiziCard::CARD_TYPE_ROCKET) {
                $count++;
            }
        }

        return $count;
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
