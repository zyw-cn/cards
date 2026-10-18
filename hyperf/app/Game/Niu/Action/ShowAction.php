<?php

declare(strict_types=1);

namespace App\Game\Niu\Action;

use App\Exception\ApiException;
use App\Facades\Log;
use App\Game\Contract\GameActionInterface;
use App\Game\Contract\GameContext;
use App\Game\Niu\CallBack;
use App\Game\Niu\Card\NiuCard;
use App\Game\Niu\NiuConsts;
use App\Game\Niu\RoundPhase;
use App\Game\Platform\Timeout\RoomTimeoutService;
use App\Game\Support\GameRecordHooks;
use App\Model\Game\GameRoom;
use App\Model\Game\GameRound;
use App\Service\Game\GamePlayCardService;
use App\Service\Game\GameRoundService;
use App\Service\Game\NiuSettleService;
use App\Service\Game\RoomFeeService;
use App\Service\Game\RoomMemberService;
use App\Service\Game\RoomService;
use App\Utils\WebSocketDebug;
use App\Utils\WebSocketSession;
use Hyperf\DbConnection\Db;
use function Hyperf\Support\make;

/**
 * Niu.Show — 亮自己的牌；全员亮完后再结算
 *
 * - 手动/超时：只标记当前玩家已开牌，广播其牌面给其他人
 * - 全员都开过：再算牛结算，phase=settle
 */
final class ShowAction implements GameActionInterface
{
    public function handle(GameContext $ctx): array
    {
        return $this->run($ctx->body);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{code: int, message: string, data: mixed, _broadcast?: array}
     */
    public function run(array $body): array
    {
        $playerId = WebSocketSession::uid($body);
        $fd = (int) ($body['fd'] ?? 0);
        $auto = ! empty($body['_auto']);
        if ($playerId <= 0) {
            return ['code' => 401, 'message' => '请先登录', 'data' => null];
        }

        $roomMemberService = make(RoomMemberService::class);
        $gameRoundService = make(GameRoundService::class);

        $member = $roomMemberService->getActiveByPlayer($playerId);
        if ($member === null) {
            return ['code' => 400, 'message' => '您还未加入任何房间', 'data' => null];
        }
        $roomId = (int) $member->room_id;
        $seatNo = (int) ($member->seat_no ?? 0);

        try {
            $outcome = Db::transaction(function () use (
                $roomId,
                $playerId,
                $seatNo,
                $auto,
                $gameRoundService,
                $roomMemberService
            ) {
                $round = $gameRoundService->lockForUpdate(
                    ['room_id' => $roomId, 'status' => GameRound::STATUS_PLAYING],
                    ['id', 'master_id', 'status', 'extra', 'multiple']
                );
                if ($round === null) {
                    throw new ApiException('本局尚未开始或已结束');
                }
                if (RoundPhase::phaseOf($round->extra) !== NiuConsts::PHASE_SHOW) {
                    throw new ApiException('当前不是亮牌阶段');
                }
                $roundId = (int) $round->id;
                $bankerPid = (int) ($round->master_id ?? 0);
                $grabMultiple = max(1, (int) ($round->multiple ?? 1));
                $phaseState = RoundPhase::decode($round->extra);
                $shownIds = array_values(array_map('intval', $phaseState['shown_ids'] ?? []));
                $members = $roomMemberService->getActiveInRoom($roomId, 'fd,player_id,seat_no');
                $alivePids = [];
                foreach ($members as $m) {
                    $pid = (int) ($m['player_id'] ?? 0);
                    if ($pid > 0) {
                        $alivePids[] = $pid;
                    }
                }
                if (! in_array($playerId, $alivePids, true)) {
                    throw new ApiException('您不在本局中');
                }

                // 已开过：幂等返回当前状态（不重复结算）
                if (in_array($playerId, $shownIds, true)) {
                    $hand = self::buildPublicHand($roomId, $roundId, $playerId, $seatNo);
                    $roomRow = make(RoomService::class)->getOne(['id' => $roomId], 'room_num');

                    return [
                        'type'         => 'partial',
                        'already'      => true,
                        'room_num'     => (string) ($roomRow->room_num ?? ''),
                        'round_id'     => $roundId,
                        'bankerPid'    => $bankerPid,
                        'grabMultiple' => $grabMultiple,
                        'shownIds'     => $shownIds,
                        'showSeat'     => $seatNo,
                        'showPlayerId' => $playerId,
                        'hand'         => $hand,
                        'members'      => $members,
                        'auto'         => $auto,
                    ];
                }

                $shownIds[] = $playerId;
                $shownIds = array_values(array_unique($shownIds));
                $dealStep = (int) ($phaseState['deal_step'] ?? NiuConsts::CARDS_PER_PLAYER);
                $gameRoundService->update($roundId, [
                    'extra' => RoundPhase::encode(
                        NiuConsts::PHASE_SHOW,
                        $dealStep,
                        null,
                        null,
                        $shownIds
                    ),
                ]);

                $hand = self::buildPublicHand($roomId, $roundId, $playerId, $seatNo);
                $allShown = self::allAliveShown($alivePids, $shownIds);

                if (! $allShown) {
                    $room = make(RoomService::class)->getOne(['id' => $roomId], 'room_num');

                    return [
                        'type'         => 'partial',
                        'already'      => false,
                        'room_num'     => (string) ($room->room_num ?? ''),
                        'round_id'     => $roundId,
                        'bankerPid'    => $bankerPid,
                        'grabMultiple' => $grabMultiple,
                        'shownIds'     => $shownIds,
                        'showSeat'     => $seatNo,
                        'showPlayerId' => $playerId,
                        'hand'         => $hand,
                        'members'      => $members,
                        'auto'         => $auto,
                    ];
                }

                // 全员已开：结算
                return self::settleAll(
                    $roomId,
                    $roundId,
                    $bankerPid,
                    $grabMultiple,
                    $seatNo,
                    $playerId,
                    $shownIds,
                    $members,
                    $gameRoundService,
                    $auto,
                    $hand
                );
            });
        } catch (ApiException $e) {
            WebSocketDebug::log('niu_show_reject', [
                'uid'     => $playerId,
                'seat'    => $seatNo,
                'message' => $e->getMessage(),
            ]);

            return ['code' => 400, 'message' => $e->getMessage(), 'data' => null];
        } catch (\Throwable $e) {
            Log::error('斗牛亮牌失败', [
                'room_id'   => $roomId,
                'player_id' => $playerId,
                'error'     => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);

            return ['code' => 500, 'message' => '亮牌失败，请重试', 'data' => null];
        }

        if (($outcome['type'] ?? '') === 'partial') {
            // 开牌倒计时全桌共用 10s：有人亮牌不重挂；到点一次性自动开剩余玩家
            if (empty($outcome['already'])) {
                self::appendShowRecord($outcome);
            }
            $data = [
                'callback'       => CallBack::SHOW,
                'phase'          => NiuConsts::PHASE_SHOW,
                'roomId'         => $outcome['room_num'],
                'roundId'        => $outcome['round_id'],
                'bankerPlayerId' => $outcome['bankerPid'],
                'grabMultiple'   => $outcome['grabMultiple'],
                'showIndex'      => $outcome['showSeat'],
                'showPlayerId'   => $outcome['showPlayerId'],
                'shownIds'       => $outcome['shownIds'],
                'hand'           => $outcome['hand'],
                'auto'           => ! empty($outcome['auto']),
                'already'        => ! empty($outcome['already']),
            ];

            return [
                'code'       => 0,
                'message'    => 'ok',
                'data'       => $data,
                '_broadcast' => [
                    'targetFds' => $roomMemberService->otherFds($outcome['members'], $fd),
                    'payload'   => ['code' => 0, 'message' => 'ok', 'data' => $data],
                ],
            ];
        }

        // 全员结算
        $feePack = make(RoomFeeService::class)->chargeAfterSettle(
            $roomId,
            (int) $outcome['round_id'],
            ['rewards' => $outcome['rewards'] ?? []]
        );
        $rewards = $feePack['settle']['rewards'] ?? ($outcome['rewards'] ?? []);
        $fee = $feePack['fee'] ?? [
            'charged'   => false,
            'payMode'   => 0,
            'payAmount' => '0.00',
            'charges'   => [],
        ];

        $feeByPid = [];
        foreach ($rewards as $rw) {
            $pid = (int) ($rw['playerId'] ?? 0);
            if ($pid > 0) {
                $feeByPid[$pid] = $rw;
            }
        }
        $hands = $outcome['hands'];
        foreach ($hands as &$hand) {
            $pid = (int) ($hand['playerId'] ?? 0);
            if ($pid > 0 && isset($feeByPid[$pid])) {
                $hand['points'] = (float) ($feeByPid[$pid]['points'] ?? $hand['points'] ?? 0);
                $hand['platformFee'] = (float) ($feeByPid[$pid]['platformFee'] ?? 0);
                $hand['roomFee'] = (float) ($feeByPid[$pid]['roomFee'] ?? $hand['platformFee']);
            } else {
                $hand['platformFee'] = (float) ($hand['platformFee'] ?? 0);
                $hand['roomFee'] = (float) ($hand['roomFee'] ?? 0);
            }
        }
        unset($hand);

        make(RoomTimeoutService::class)->armGameTimeout($roomId);

        if (! empty($outcome['roomOver'])) {
            $roomMemberService->markEndedForRoom($roomId);
        }

        $data = [
            'callback'       => CallBack::SHOW,
            'phase'          => 'settle',
            'roomId'         => $outcome['room_num'],
            'roundId'        => $outcome['round_id'],
            'bankerIndex'    => $outcome['bankerSeat'],
            'bankerPlayerId' => $outcome['bankerPid'],
            'grabMultiple'   => $outcome['grabMultiple'],
            'hands'          => $hands,
            'rewards'        => $rewards,
            'platformFee'    => [
                'charged'   => ! empty($fee['charged']),
                'payMode'   => (int) ($fee['payMode'] ?? 0),
                'payAmount' => (string) ($fee['payAmount'] ?? '0.00'),
                'charges'   => $fee['charges'] ?? [],
            ],
            'playedCount'    => $outcome['playedCount'],
            'totalCount'     => $outcome['totalCount'],
            'roomOver'       => $outcome['roomOver'],
            'showIndex'      => $outcome['showSeat'],
            'showPlayerId'   => $outcome['showPlayerId'],
            'shownIds'       => $outcome['shownIds'],
            'auto'           => ! empty($outcome['auto']),
        ];

        return [
            'code'       => 0,
            'message'    => 'ok',
            'data'       => $data,
            '_broadcast' => [
                'targetFds' => $roomMemberService->otherFds($outcome['members'], $fd),
                'payload'   => ['code' => 0, 'message' => 'ok', 'data' => $data],
            ],
        ];
    }

    /**
     * @param list<int> $alivePids
     * @param list<int> $shownIds
     */
    private static function allAliveShown(array $alivePids, array $shownIds): bool
    {
        if ($alivePids === []) {
            return false;
        }
        $set = array_fill_keys($shownIds, true);
        foreach ($alivePids as $pid) {
            if (! isset($set[$pid])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{playerId:int, seatNo:int, cards:list<string>, niuType:int, niuLabel:string, niuMultiple:int}
     */
    private static function buildPublicHand(int $roomId, int $roundId, int $playerId, int $seatNo): array
    {
        $cards = make(GamePlayCardService::class)->getLatestHand($roundId, $playerId);
        $room = make(RoomService::class)->getOne(['id' => $roomId], 'id,rule');
        $multipliers = make(RoomService::class)->resolveNiuMultipliers($room->rule ?? null);
        $ev = NiuCard::evaluate($cards, $multipliers);

        return [
            'playerId'    => $playerId,
            'seatNo'      => $seatNo,
            'cards'       => array_values($cards),
            'niuType'     => (int) ($ev['type'] ?? 0),
            'niuLabel'    => (string) ($ev['label'] ?? '没牛'),
            'niuMultiple' => (int) ($ev['multiple'] ?? 1),
        ];
    }

    /**
     * @param array<string, mixed> $outcome
     */
    private static function appendShowRecord(array $outcome): void
    {
        $hand = is_array($outcome['hand'] ?? null) ? $outcome['hand'] : [];
        $tiles = array_values(is_array($hand['cards'] ?? null) ? $hand['cards'] : []);
        GameRecordHooks::append(
            (int) ($outcome['round_id'] ?? 0),
            'show',
            (int) ($outcome['showSeat'] ?? -1),
            -1,
            $tiles,
            [
                'niuType'     => (int) ($hand['niuType'] ?? 0),
                'niuMultiple' => (int) ($hand['niuMultiple'] ?? 1),
            ]
        );
    }

    /**
     * @param list<int> $shownIds
     * @param list<array<string, mixed>> $members
     * @param array{playerId:int, seatNo:int, cards:list<string>, niuType:int, niuLabel:string, niuMultiple:int} $hand
     * @return array<string, mixed>
     */
    private static function settleAll(
        int $roomId,
        int $roundId,
        int $bankerPid,
        int $grabMultiple,
        int $showSeat,
        int $showPlayerId,
        array $shownIds,
        array $members,
        GameRoundService $gameRoundService,
        bool $auto,
        array $hand
    ): array {
        $settle = make(NiuSettleService::class)->settle($roomId, $roundId, $bankerPid, $grabMultiple);

        self::appendShowRecord([
            'round_id' => $roundId,
            'showSeat' => $showSeat,
            'hand'     => $hand,
        ]);
        self::finishGameRecord($roundId, $settle, $bankerPid, $grabMultiple);

        $winnerHands = array_values(array_filter(
            $settle['hands'],
            static fn (array $h): bool => (float) ($h['delta'] ?? 0) > 0
        ));
        usort(
            $winnerHands,
            static fn (array $a, array $b): int => (float) ($b['delta'] ?? 0) <=> (float) ($a['delta'] ?? 0)
        );
        $winnerIds = array_values(array_unique(array_map(
            static fn (array $h): int => (int) $h['playerId'],
            $winnerHands
        )));
        $gameRoundService->update($roundId, [
            'status'          => GameRound::STATUS_ENDED,
            'winner_type'     => in_array($bankerPid, $winnerIds, true) ? 1 : 2,
            'winner_user_ids' => implode(',', array_values(array_unique($winnerIds))),
            'end_time'        => date('Y-m-d H:i:s'),
        ]);

        $roomService = make(RoomService::class);
        $room = $roomService->lockForUpdate(
            ['id' => $roomId],
            ['id', 'played_count', 'total_play_count', 'status', 'room_num']
        );
        if ($room === null) {
            throw new ApiException('房间不存在');
        }
        $playedCount = (int) ($room->played_count ?? 0) + 1;
        $total = (int) ($room->total_play_count ?? 0);
        $roomOver = $total > 0 && $playedCount >= $total;
        $roomUpdate = ['played_count' => $playedCount];
        if ($roomOver) {
            $roomUpdate['status'] = GameRoom::STATUS_ENDED;
            $roomUpdate['end_time'] = date('Y-m-d H:i:s');
        }
        $roomService->update($roomId, $roomUpdate);

        $bankerSeat = -1;
        foreach ($settle['hands'] as $h) {
            if (! empty($h['isBanker'])) {
                $bankerSeat = (int) $h['seatNo'];
                break;
            }
        }

        return [
            'type'         => 'settle',
            'room_num'     => (string) ($room->room_num ?? ''),
            'round_id'     => $roundId,
            'bankerSeat'   => $bankerSeat,
            'bankerPid'    => $bankerPid,
            'grabMultiple' => $grabMultiple,
            'hands'        => $settle['hands'],
            'rewards'      => $settle['rewards'],
            'playedCount'  => $playedCount,
            'totalCount'   => $total,
            'roomOver'     => $roomOver,
            'members'      => $members,
            'showSeat'     => $showSeat,
            'showPlayerId' => $showPlayerId,
            'shownIds'     => $shownIds,
            'auto'         => $auto,
            'hand'         => $hand,
        ];
    }

    /**
     * @param array<string, mixed> $settle
     */
    private static function finishGameRecord(
        int $roundId,
        array $settle,
        int $bankerPid,
        int $grabMultiple
    ): void {
        $rewards = is_array($settle['rewards'] ?? null) ? $settle['rewards'] : [];
        $hands = is_array($settle['hands'] ?? null) ? $settle['hands'] : [];
        $scores = [];
        $winners = [];
        $playerScores = [];
        $bankerSeat = -1;

        foreach ($rewards as $rw) {
            if (! is_array($rw)) {
                continue;
            }
            $pid = (int) ($rw['playerId'] ?? 0);
            $seat = (int) ($rw['seatNo'] ?? 0);
            $delta = $rw['delta'] ?? 0;
            $scores[(string) $seat] = $delta;
            $isWinner = (float) $delta > 0;
            if ($isWinner) {
                $winners[] = $seat;
            }
            $isBanker = ! empty($rw['isBanker']) || $pid === $bankerPid;
            if ($isBanker) {
                $bankerSeat = $seat;
            }
            if ($pid > 0) {
                $playerScores[] = [
                    'memberId' => $pid,
                    'score'    => $delta,
                    'isWinner' => $isWinner,
                    'isBanker' => $isBanker,
                ];
            }
        }

        $resultHands = [];
        foreach ($hands as $h) {
            if (! is_array($h)) {
                continue;
            }
            $resultHands[] = [
                'seat'        => (int) ($h['seatNo'] ?? 0),
                'cards'       => array_values(is_array($h['cards'] ?? null) ? $h['cards'] : []),
                'niuType'     => (int) ($h['niuType'] ?? 0),
                'niuLabel'    => (string) ($h['niuLabel'] ?? ''),
                'betMultiple' => (int) ($h['betMultiple'] ?? 0),
                'delta'       => $h['delta'] ?? 0,
                'win'         => ! empty($h['win']) || (float) ($h['delta'] ?? 0) > 0,
            ];
            if (! empty($h['isBanker'])) {
                $bankerSeat = (int) ($h['seatNo'] ?? $bankerSeat);
            }
        }

        GameRecordHooks::finish($roundId, [
            'scores'  => $scores,
            'winners' => $winners,
            'ext'     => [
                'grabMultiple' => $grabMultiple,
                'bankerSeat'   => $bankerSeat,
                'hands'        => $resultHands,
            ],
        ], $playerScores);
    }
}
