<?php

declare(strict_types=1);

namespace App\Game\Zjh\Action;

use App\Exception\ApiException;
use App\Facades\Log;
use App\Game\Contract\GameActionInterface;
use App\Game\Contract\GameContext;
use App\Game\Zjh\CallBack;
use App\Game\Zjh\RoundState;
use App\Game\Zjh\Support;
use App\Game\Zjh\ZjhConsts;
use App\Game\Platform\Timeout\RoomTimeoutService;
use App\Game\Support\GameRecordHooks;
use App\Model\Game\GameRound;
use App\Service\Game\GameRoundService;
use App\Service\Game\RoomFeeService;
use App\Service\Game\RoomMemberService;
use App\Service\Game\RoomService;
use App\Service\Game\ZjhChipService;
use App\Service\Game\ZjhSettleService;
use App\Utils\WebSocketSession;
use Hyperf\DbConnection\Db;
use function Hyperf\Support\make;

/**
 * Zjh.Call — 跟注
 */
final class CallAction implements GameActionInterface
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
        if ($playerId <= 0) {
            return ['code' => 401, 'message' => '请先登录', 'data' => null];
        }

        $roomMemberService = make(RoomMemberService::class);
        $member = $roomMemberService->getActiveByPlayer($playerId);
        if ($member === null) {
            return ['code' => 400, 'message' => '您还未加入任何房间', 'data' => null];
        }
        $roomId = (int) $member->room_id;
        $seatNo = (int) ($member->seat_no ?? 0);

        try {
            $outcome = Db::transaction(function () use ($roomId, $playerId, $seatNo, $body) {
                return self::act($roomId, $playerId, $seatNo, ! empty($body['_auto']));
            });
        } catch (ApiException $e) {
            return ['code' => 400, 'message' => $e->getMessage(), 'data' => null];
        } catch (\Throwable $e) {
            Log::error('炸金花跟注失败', [
                'room_id' => $roomId, 'player_id' => $playerId, 'error' => $e->getMessage(),
            ]);

            return ['code' => 500, 'message' => '跟注失败，请重试', 'data' => null];
        }

        if (empty($body['_auto'])) {
            $roomMemberService->clearTrust($roomId, $playerId);
        }
        make(RoomTimeoutService::class)->armGameTimeout($roomId);

        return self::respond($outcome, $fd, CallBack::CALL, $seatNo);
    }

    /**
     * @return array<string, mixed>
     */
    public static function act(int $roomId, int $playerId, int $seatNo, bool $auto = false): array
    {
        $round = make(GameRoundService::class)->lockForUpdate(
            ['room_id' => $roomId, 'status' => GameRound::STATUS_PLAYING],
            ['id', 'extra', 'status']
        );
        if ($round === null) {
            throw new ApiException('本局尚未开始或已结束');
        }
        $state = RoundState::decode($round->extra);
        if ($state['phase'] !== ZjhConsts::PHASE_BET) {
            throw new ApiException('当前不是下注阶段');
        }
        if ((int) $state['turnSeat'] !== $seatNo) {
            throw new ApiException('还没轮到您操作');
        }
        if (RoundState::playerIdOf($state, $seatNo) !== $playerId) {
            throw new ApiException('座位信息不匹配');
        }
        if (RoundState::isFolded($state, $seatNo)) {
            throw new ApiException('已弃牌');
        }

        $room = make(RoomService::class)->getOne(['id' => $roomId], 'id,rule');
        $caps = ZjhConsts::capsFromRule($room->rule ?? null);
        $amount = RoundState::callAmount($state, $seatNo, $caps['select_points']);
        $roundId = (int) $round->id;
        $after = make(ZjhChipService::class)->deduct(
            $playerId,
            $amount,
            sprintf('炸金花跟注 房间%d 局%d', $roomId, $roundId)
        );
        $settleSvc = make(ZjhSettleService::class);
        $kind = RoundState::isLooked($state, $seatNo) ? 'call' : 'men';
        $state = $settleSvc->applyChipIn($state, $seatNo, $amount, $kind);
        $state['turnSeat'] = RoundState::nextAliveSeat($state, $seatNo);

        return self::persistAndMaybeSettle($roomId, $roundId, $state, $settleSvc, [
            'action'   => 'call',
            'amount'   => $amount,
            'auto'     => $auto,
            'seat'     => $seatNo,
            'points'   => (float) $after,
            'playerId' => $playerId,
        ]);
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    public static function persistAndMaybeSettle(
        int $roomId,
        int $roundId,
        array $state,
        ZjhSettleService $settleSvc,
        array $meta
    ): array {
        self::appendActionRecord($roundId, $meta);

        $alive = RoundState::aliveSeats($state);
        $room = make(RoomService::class)->getOne(['id' => $roomId], 'id,room_num,rule');
        $roomNum = (string) ($room->room_num ?? '');
        $rule = $room->rule ?? null;
        $members = make(RoomMemberService::class)->getActiveInRoom($roomId, 'fd,player_id');

        if (count($alive) <= 1) {
            $winnerSeat = $alive[0] ?? (int) $state['turnSeat'];
            $winnerPid = RoundState::playerIdOf($state, $winnerSeat);
            $settle = $settleSvc->settleToWinner($roomId, $roundId, $winnerPid, $state);
            self::finishGameRecord($roundId, $settle, $state);

            return [
                'finished' => true,
                'settle'   => $settle,
                'meta'     => $meta,
                'members'  => $members,
                'room_num' => $settle['room_num'] !== '' ? $settle['room_num'] : $roomNum,
                'round_id' => $roundId,
                'room_id'  => $roomId,
                'rule'     => $rule,
            ];
        }

        make(GameRoundService::class)->update($roundId, [
            'extra' => RoundState::encode($state),
        ]);

        return [
            'finished' => false,
            'state'    => $state,
            'meta'     => $meta,
            'members'  => $members,
            'room_num' => $roomNum,
            'round_id' => $roundId,
            'room_id'  => $roomId,
            'rule'     => $rule,
        ];
    }

    /**
     * @param array<string, mixed> $meta
     */
    private static function appendActionRecord(int $roundId, array $meta): void
    {
        $op = (string) ($meta['action'] ?? '');
        if ($op === '') {
            return;
        }
        $seat = (int) ($meta['seat'] ?? -1);
        $ext = [];
        if ($op === 'call' || $op === 'raise') {
            $ext['amount'] = $meta['amount'] ?? 0;
            if ($op === 'raise' && isset($meta['unit'])) {
                $ext['unit'] = $meta['unit'];
            }
        } elseif ($op === 'compare') {
            $target = (int) ($meta['target'] ?? -1);
            $ext['targetSeat'] = $target;
            $ext['winSeat'] = ! empty($meta['win']) ? $seat : $target;
        }
        $fromSeat = $op === 'compare' ? (int) ($meta['target'] ?? -1) : -1;
        GameRecordHooks::append($roundId, $op, $seat, $fromSeat, [], $ext);
    }

    /**
     * @param array<string, mixed> $settle
     * @param array<string, mixed> $state
     */
    private static function finishGameRecord(int $roundId, array $settle, array $state): void
    {
        $rewards = is_array($settle['rewards'] ?? null) ? $settle['rewards'] : [];
        $hands = is_array($settle['hands'] ?? null) ? $settle['hands'] : [];
        $winnerSeat = (int) ($settle['winnerSeat'] ?? -1);
        $dealerSeat = (int) ($state['dealerSeat'] ?? -1);
        $scores = [];
        $playerScores = [];
        foreach ($rewards as $rw) {
            if (! is_array($rw)) {
                continue;
            }
            $pid = (int) ($rw['playerId'] ?? 0);
            $seat = (int) ($rw['seatNo'] ?? 0);
            $delta = $rw['delta'] ?? 0;
            $scores[(string) $seat] = $delta;
            if ($pid > 0) {
                $playerScores[] = [
                    'memberId' => $pid,
                    'score'    => $delta,
                    'isWinner' => $winnerSeat >= 0 && $seat === $winnerSeat,
                    'isBanker' => $dealerSeat >= 0 && $seat === $dealerSeat,
                ];
            }
        }

        $resultHands = [];
        foreach ($hands as $h) {
            if (! is_array($h)) {
                continue;
            }
            $resultHands[] = [
                'seat'     => (int) ($h['seatNo'] ?? 0),
                'cards'    => array_values(is_array($h['cards'] ?? null) ? $h['cards'] : []),
                'type'     => $h['type'] ?? 0,
                'label'    => (string) ($h['label'] ?? ''),
                'folded'   => ! empty($h['folded']),
                'looked'   => ! empty($h['looked']),
                'invested' => $h['invested'] ?? 0,
                'delta'    => $h['delta'] ?? 0,
                'bill'     => (string) ($h['bill'] ?? ''),
                'win'      => ! empty($h['win']),
            ];
        }

        GameRecordHooks::finish($roundId, [
            'scores'  => $scores,
            'winners' => $winnerSeat >= 0 ? [$winnerSeat] : [],
            'ext'     => [
                'pot'        => $settle['pot'] ?? '0.00',
                'winnerSeat' => $winnerSeat,
                'hands'      => $resultHands,
                'roomOver'   => (bool) ($settle['roomOver'] ?? false),
            ],
        ], $playerScores);
    }

    /**
     * @param array<string, mixed> $outcome
     * @return array{code: int, message: string, data: mixed, _broadcast?: array}
     */
    public static function respond(array $outcome, int $fd, string $callback, int $seatNo): array
    {
        $roomMemberService = make(RoomMemberService::class);
        if (! empty($outcome['finished'])) {
            $settle = $outcome['settle'];
            // 有输赢才扣场地费（失败不回滚输赢）
            if (empty($settle['noCompare'])) {
                $feePack = make(RoomFeeService::class)->chargeAfterSettle(
                    (int) ($outcome['room_id'] ?? 0),
                    (int) ($outcome['round_id'] ?? 0),
                    ['rewards' => $settle['rewards'] ?? []]
                );
                $settle['rewards'] = $feePack['settle']['rewards'] ?? ($settle['rewards'] ?? []);
            }

            if (! empty($settle['roomOver'])) {
                $roomMemberService->markEndedForRoom((int) ($outcome['room_id'] ?? 0));
            }
            $data = [
                'callback'    => CallBack::SETTLE,
                'phase'       => ZjhConsts::PHASE_SETTLE,
                'roomId'      => $outcome['room_num'],
                'roundId'     => $outcome['round_id'],
                'winnerIndex' => $settle['winnerSeat'],
                'winnerPid'   => $settle['winnerPid'],
                'pot'         => (float) $settle['pot'],
                'hands'       => $settle['hands'],
                'rewards'     => $settle['rewards'],
                'playedCount' => $settle['playedCount'],
                'totalCount'  => $settle['totalCount'],
                'roomOver'    => $settle['roomOver'],
                'noCompare'   => ! empty($settle['noCompare']),
                'lastAction'  => array_merge($outcome['meta'], ['index' => $seatNo, 'callback' => $callback]),
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

        $table = Support::publicTable(
            $outcome['state'],
            $outcome['room_num'],
            (int) $outcome['round_id'],
            $outcome['rule'] ?? null
        );
        $data = array_merge($table, [
            'callback'   => $callback,
            'lastAction' => [
                'index'  => $seatNo,
                'action' => (string) ($outcome['meta']['action'] ?? ''),
                'amount' => (float) ($outcome['meta']['amount'] ?? 0),
                'auto'   => ! empty($outcome['meta']['auto']),
                'target' => (int) ($outcome['meta']['target'] ?? -1),
                'win'    => $outcome['meta']['win'] ?? null,
                'loser'  => (int) ($outcome['meta']['loser'] ?? -1),
                'unit'   => isset($outcome['meta']['unit']) ? (float) $outcome['meta']['unit'] : null,
                'points' => isset($outcome['meta']['points']) ? (float) $outcome['meta']['points'] : null,
                'playerId' => (int) ($outcome['meta']['playerId'] ?? 0),
            ],
        ]);

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
}
