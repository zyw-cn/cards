<?php

declare(strict_types=1);

namespace App\Game\Dou\Action;

use App\Exception\ApiException;
use App\Facades\Log;
use App\Game\Dou\CallBack;
use App\Game\Dou\DealBootstrap;
use App\Game\Dou\DouConsts;
use App\Game\Dou\RoundState;
use App\Model\Game\GameRoom;
use App\Model\Game\GameRound;
use App\Service\Game\GameRoundService;
use App\Service\Game\LandLordBidService;
use App\Service\Game\RoomMemberService;
use App\Service\Game\RoomService;
use App\Game\Platform\Start\SharedStartGame;
use Hyperf\DbConnection\Db;
use App\Game\Contract\GameActionInterface;
use App\Game\Contract\GameContext;
use function Hyperf\Support\make;

/**
 * Dou.StartGame — 确认开始
 *
 * - 抢庄模式：满 3 准备后先发牌，再进入叫地主/抢地主
 * - 轮流 / 胜者连庄：满 3 准备后发牌，方片3 为地主并先出（首局）
 */
final class StartGameAction implements GameActionInterface
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
        $session = SharedStartGame::resolveSession($body);
        if (isset($session['error'])) {
            return $session['error'];
        }
        $playerId = $session['playerId'];
        $roomId = $session['roomId'];
        $roomMemberService = $session['roomMemberService'];
        $roomService = $session['roomService'];
        $gameRoundService = $session['gameRoundService'];

        try {
            $outcome = Db::transaction(function () use (
                $roomId,
                $playerId,
                $roomService,
                $gameRoundService,
                $roomMemberService
            ) {
                $room = SharedStartGame::lockRoomForStart($roomService, $roomId);

                $rulePayload = RoundState::rulePayloadFromRule($room->rule ?? null);
                $askMode = (int) $rulePayload['askMode'];

                $members = self::enrichMembers($roomMemberService->getActiveInRoom($roomId));
                if ($members !== []) {
                    self::assertGoldEnough($roomService, $room->rule ?? null, $members);
                }

                $round = self::pickOpenRoundRow($roomId, $gameRoundService);

                if ($round === null) {
                    $roundId = $gameRoundService->save([
                        'room_id'    => $roomId,
                        'player_ids' => (string) $playerId,
                        'status'     => GameRound::STATUS_PENDING,
                    ]);

                    return self::attachRoomRuleToOutcome([
                        'mode'      => 'wait',
                        'round_id'  => $roundId,
                        'room_num'  => (string) $room->room_num,
                        'ready_ids' => [$playerId],
                    ], $room);
                }

                $roundModel = $gameRoundService->lockForUpdate(
                    (int) self::roundField($round, 'id'),
                    ['id', 'status', 'player_ids', 'master_id', 'winner_user_ids', 'extra']
                );
                if ($roundModel === null) {
                    throw new ApiException('开局失败，请重试');
                }
                $round = $roundModel;

                if ((int) $round->status === GameRound::STATUS_PLAYING) {
                    $state = RoundState::decode($round->extra ?? null);
                    // 叫地主 / 出牌中均算已开始
                    if (in_array($state['phase'], [DouConsts::PHASE_GRAB, DouConsts::PHASE_DOUBLE, DouConsts::PHASE_PLAY], true)
                        && ((int) $round->master_id > 0 || $state['phase'] !== DouConsts::PHASE_PLAY || ! empty($state['grabs']) || $state['phase'] === DouConsts::PHASE_GRAB)
                    ) {
                        return [
                            'mode'     => 'started',
                            'round_id' => $round->id,
                            'room_num' => (string) $room->room_num,
                        ];
                    }
                }

                $readyIds = self::parseIds($round->player_ids);
                if (! in_array($playerId, $readyIds, true)) {
                    $readyIds[] = $playerId;
                    $gameRoundService->update($round->id, ['player_ids' => implode(',', $readyIds)]);
                }

                if (count($readyIds) < DouConsts::MAX_PLAYERS) {
                    return self::attachRoomRuleToOutcome([
                        'mode'      => 'wait',
                        'round_id'  => $round->id,
                        'room_num'  => (string) $room->room_num,
                        'ready_ids' => $readyIds,
                    ], $room);
                }

                if ($members === []) {
                    $members = self::enrichMembers($roomMemberService->getActiveInRoom($roomId));
                }

                if ($askMode === DouConsts::ASK_GRAB) {
                    return self::attachRoomRuleToOutcome(
                        self::startGrabPhase($room, $round, $readyIds, $members, $askMode),
                        $room
                    );
                }

                // 轮流 / 胜者连庄：不发叫抢，直接定庄出牌（仅 ask_mode≠2）
                return self::attachRoomRuleToOutcome(
                    self::startDealWithDiamond3($room, $round, $readyIds, $members, $askMode),
                    $room
                );
            });
        } catch (ApiException $e) {
            return ['code' => 400, 'message' => $e->getMessage(), 'data' => null];
        } catch (\Throwable $e) {
            Log::error('开始游戏失败', [
                'room_id'   => $roomId,
                'player_id' => $playerId,
                'error'     => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);

            return ['code' => 500, 'message' => '开始游戏失败，请重试', 'data' => null];
        }

        SharedStartGame::armTimeout($roomId);

        if (($outcome['mode'] ?? '') === 'started') {
            return ['code' => 400, 'message' => '本局已开始', 'data' => null];
        }

        if (($outcome['mode'] ?? '') === 'wait') {
            return self::buildWaitBroadcast($outcome, $roomId, $roomMemberService);
        }

        if (($outcome['mode'] ?? '') === 'grab') {
            return self::buildGrabBroadcast($outcome, $playerId);
        }

        // deal + play（非抢庄规则）
        return self::buildDealBroadcast($outcome, $playerId);
    }

    /**
     * 取当前房间「未结束」的最新一局；已结算但未标 ENDED 的局会被收口。
     *
     * @return object|null 含 id/status/player_ids/master_id/winner_user_ids/extra
     */
    private static function pickOpenRoundRow(int $roomId, GameRoundService $gameRoundService): ?object
    {
        return SharedStartGame::pickOpenRoundRow(
            $roomId,
            $gameRoundService,
            static function (array|object $round): bool {
                $phase = (string) (RoundState::decode(SharedStartGame::roundField($round, 'extra'))['phase'] ?? '');

                return self::isRoundActivelyPlaying($round, $phase);
            }
        );
    }

    private static function roundField(array|object $row, string $key): mixed
    {
        return SharedStartGame::roundField($row, $key);
    }

    private static function roundSetField(array|object &$row, string $key, mixed $value): void
    {
        SharedStartGame::roundSetField($row, $key, $value);
    }

    /** @param array<string, mixed>|object $row */
    private static function roundAsObject(array|object $row): object
    {
        return SharedStartGame::roundAsObject($row);
    }

    /** 叫抢 / 加倍 / 已确定地主的出牌阶段 */
    private static function isRoundActivelyPlaying(array|object $round, string $phase): bool
    {
        if (in_array($phase, [DouConsts::PHASE_GRAB, DouConsts::PHASE_DOUBLE], true)) {
            return true;
        }

        return $phase === DouConsts::PHASE_PLAY && (int) self::roundField($round, 'master_id') > 0;
    }

    /**
     * @param array<string, mixed> $outcome
     * @return array{code: int, message: string, data: mixed, _broadcast?: array}
     */
    private static function buildWaitBroadcast(array $outcome, int $roomId, RoomMemberService $roomMemberService): array
    {
        return SharedStartGame::buildWaitBroadcast(
            $outcome,
            $roomId,
            $roomMemberService,
            CallBack::WAIT_START,
            ['askMode' => (int) ($outcome['askMode'] ?? DouConsts::ASK_GRAB)]
        );
    }

    /**
     * @param array<string, mixed> $outcome
     * @return array<string, mixed>
     */
    private static function attachRoomRuleToOutcome(array $outcome, object $room): array
    {
        $payload = RoundState::rulePayloadFromRule($room->rule ?? null);
        $outcome['askMode'] = $payload['askMode'];
        $outcome['rule'] = $payload['rule'];
        $outcome['ruleObj'] = $payload['ruleObj'];

        return $outcome;
    }

    /**
     * @param list<array<string,mixed>> $members
     * @return list<array<string,mixed>>
     */
    private static function enrichMembers(array $members): array
    {
        return SharedStartGame::enrichMembers($members);
    }

    /**
     * @param list<array<string,mixed>> $members
     */
    private static function assertGoldEnough(RoomService $roomService, ?string $rule, array $members): void
    {
        SharedStartGame::assertGoldEnough($roomService, $rule, $members);
    }

    /**
     * @param list<int> $readyIds
     * @param list<array<string,mixed>> $members
     */
    private static function startGrabPhase(
        $room,
        $round,
        array $readyIds,
        array $members,
        int $askMode,
        ?int $grabStartedAt = null,
    ): array {
        $grabTurn = random_int(0, DouConsts::MAX_PLAYERS - 1);
        $dealt = DealBootstrap::dealToMembers($members);
        $state = [
            'phase'            => DouConsts::PHASE_GRAB,
            'askMode'          => $askMode,
            'grabTurn'         => $grabTurn,
            'grabs'            => [],
            'farmerDoubles'    => [],
            'remain'           => $dealt['remain'],
            'landlordSeat'     => -1,
            'landlordMultiple' => 1,
            'grabStartedAt'    => $grabStartedAt > 0 ? $grabStartedAt : time(),
        ];

        $players = DealBootstrap::persistHandsForGrab(
            (int) $room->id,
            (int) $round->id,
            $dealt['players'],
            $dealt['remain'],
            $state
        );

        $firstPid = 0;
        foreach ($members as $m) {
            if ((int) ($m['seat_no'] ?? -1) === $grabTurn) {
                $firstPid = (int) ($m['player_id'] ?? 0);
                break;
            }
        }
        make(LandLordBidService::class)->save([
            'game_round_id' => (int) $round->id,
            'seq'           => 0,
            'seat_no'       => $grabTurn,
            'member_id'     => $firstPid,
            'action'        => 0,
            'score'         => 0,
            'multiple'      => 1,
        ]);

        return [
            'mode'       => 'grab',
            'round_id'   => (int) $round->id,
            'room_num'   => (string) $room->room_num,
            'ready_ids'  => $readyIds,
            'grabTurn'   => $grabTurn,
            'askMode'    => $askMode,
            'players'    => $players,
            'remain'     => $dealt['remain'],
            'grabScores' => DouConsts::GRAB_SCORES,
        ];
    }

    /**
     * 轮流 / 胜者连庄：发牌后以方片3 座位为地主并先出（首局）；后续局按规则换庄
     *
     * @param list<int> $readyIds
     * @param list<array<string,mixed>> $members
     */
    private static function startDealWithDiamond3($room, $round, array $readyIds, array $members, int $askMode): array
    {
        $dealt = DealBootstrap::dealToMembers($members);
        $landlordSeat = $dealt['diamond3Seat'];

        // 非首局：轮流 = 上一局地主下家；胜者连庄 = 上一局赢家座位
        $played = (int) ($room->played_count ?? 0);
        if ($played > 0) {
            $prev = make(GameRoundService::class)->getOne(
                [
                    'room_id' => (int) $room->id,
                    'status'  => GameRound::STATUS_ENDED,
                ],
                'id,master_id',
            );
            // getOne 无 order；用 getAll 取最近一局
            $prevList = make(GameRoundService::class)->getAll(
                [
                    'room_id' => (int) $room->id,
                    'status'  => GameRound::STATUS_ENDED,
                ],
                'id,master_id',
                ['id' => 'desc']
            );
            $prevRow = $prevList[0] ?? null;
            if ($askMode === DouConsts::ASK_ROTATE && $prevRow) {
                $prevMaster = (int) ($prevRow['master_id'] ?? 0);
                $prevSeat = self::seatOfPid($members, $prevMaster);
                if ($prevSeat >= 0) {
                    $landlordSeat = ($prevSeat + 1) % DouConsts::MAX_PLAYERS;
                }
            } elseif ($askMode === DouConsts::ASK_WINNER) {
                $ruleData = json_decode((string) ($room->rule ?? ''), true) ?: [];
                $winnerPid = (int) ($ruleData['last_winner_pid'] ?? 0);
                $winSeat = self::seatOfPid($members, $winnerPid);
                if ($winSeat >= 0) {
                    $landlordSeat = $winSeat;
                }
            }
            unset($prev);
        }

        $landlordPid = DealBootstrap::pidOfSeat($dealt['players'], $landlordSeat);
        $state = [
            'phase'            => DouConsts::PHASE_PLAY,
            'askMode'          => $askMode,
            'grabTurn'         => 0,
            'grabs'            => [],
            'farmerDoubles'    => [],
            'remain'           => $dealt['remain'],
            'landlordSeat'     => $landlordSeat,
            'landlordMultiple' => 1,
        ];

        $players = DealBootstrap::persistDeal(
            (int) $room->id,
            (int) $round->id,
            $dealt['players'],
            $dealt['remain'],
            $state,
            $landlordPid,
            $landlordSeat
        );

        return [
            'mode'          => 'deal',
            'round_id'      => (int) $round->id,
            'room_num'      => (string) $room->room_num,
            'ready_ids'     => $readyIds,
            'askMode'       => $askMode,
            'players'       => $players,
            'remain'        => $dealt['remain'],
            'landlordSeat'  => $landlordSeat,
            'landlordPid'   => $landlordPid,
            'currentIndex'  => $landlordSeat,
            'multiple'      => 1,
        ];
    }

    /** @param list<array<string,mixed>> $members */
    private static function seatOfPid(array $members, int $pid): int
    {
        foreach ($members as $m) {
            if ((int) ($m['player_id'] ?? 0) === $pid) {
                return (int) ($m['seat_no'] ?? -1);
            }
        }

        return -1;
    }

    private static function buildGrabBroadcast(array $outcome, int $playerId): array
    {
        $publicSeats = [];
        $perFd = [];
        $selfData = null;
        $remain = $outcome['remain'] ?? [];
        foreach ($outcome['players'] as $p) {
            $fd = (int) ($p['fd'] ?? 0);
            $pid = (int) ($p['player_id'] ?? 0);
            $seat = (int) ($p['seat_no'] ?? 0);
            $cards = $p['cards'] ?? [];
            $row = [
                'seat_no'    => $seat,
                'player_id'  => $pid,
                'nickname'   => (string) ($p['nickname'] ?? ''),
                'avatar'     => (string) ($p['avatar'] ?? ''),
                'avatar_url' => (string) ($p['avatar_url'] ?? ''),
                'cardsCount' => is_array($cards) ? count($cards) : 0,
                'points'     => (float) ($p['points'] ?? 0),
            ];
            $publicSeats[] = $row;
            $payload = [
                'callback'   => CallBack::START_GAME,
                'roomId'     => $outcome['room_num'],
                'roundId'    => $outcome['round_id'],
                'phase'      => DouConsts::PHASE_GRAB,
                'askMode'    => (int) ($outcome['askMode'] ?? DouConsts::ASK_GRAB),
                'rule'       => (string) ($outcome['rule'] ?? ''),
                'ruleObj'    => $outcome['ruleObj'] ?? [],
                'bidTurn'    => $outcome['grabTurn'],
                'maxBid'     => 0,
                'multiple'   => 1,
                // 兼容旧前端；叫/抢模式不再用多档叫分
                'grabScores' => DouConsts::GRAB_SCORES,
                // 叫地主阶段底牌只显示牌背，不亮牌面
                'bottom'     => array_fill(0, count($remain), 'back'),
                'myCards'    => $cards,
                'players'    => [],
            ];
            if ($fd > 0) {
                $perFd[$fd] = ['code' => 0, 'message' => 'ok', 'data' => $payload];
            }
            if ($pid === $playerId) {
                $selfData = $payload;
            }
        }
        foreach ($perFd as &$item) {
            $item['data']['players'] = $publicSeats;
        }
        unset($item);
        if ($selfData !== null) {
            $selfData['players'] = $publicSeats;
        }
        foreach ($outcome['players'] as $p) {
            $fd = (int) ($p['fd'] ?? 0);
            if ($fd > 0 && isset($perFd[$fd])) {
                $perFd[$fd]['data']['myCards'] = $p['cards'] ?? [];
            }
            if ((int) ($p['player_id'] ?? 0) === $playerId && $selfData !== null) {
                $selfData['myCards'] = $p['cards'] ?? [];
            }
        }

        return [
            'code'       => 0,
            'message'    => 'ok',
            'data'       => $selfData ?? [
                'callback' => CallBack::START_GAME,
                'phase'    => DouConsts::PHASE_GRAB,
                'bidTurn'  => $outcome['grabTurn'],
            ],
            '_broadcast' => ['perFd' => $perFd],
        ];
    }

    private static function buildDealBroadcast(array $outcome, int $playerId): array
    {
        $publicSeats = [];
        $perFd = [];
        $selfData = null;
        $landlordSeat = (int) $outcome['landlordSeat'];
        $landlordPid = (int) $outcome['landlordPid'];

        foreach ($outcome['players'] as $p) {
            $fd = (int) ($p['fd'] ?? 0);
            $pid = (int) ($p['player_id'] ?? 0);
            $seat = (int) ($p['seat_no'] ?? 0);
            $cards = $p['cards'] ?? [];
            $publicSeats[] = [
                'seat_no'    => $seat,
                'player_id'  => $pid,
                'nickname'   => (string) ($p['nickname'] ?? ''),
                'avatar'     => (string) ($p['avatar'] ?? ''),
                'avatar_url' => (string) ($p['avatar_url'] ?? ''),
                'cardsCount' => is_array($cards) ? count($cards) : 0,
                'points'     => (float) ($p['points'] ?? 0),
            ];
            $payload = [
                'callback'      => CallBack::START_GAME,
                'roomId'        => $outcome['room_num'],
                'roundId'       => $outcome['round_id'],
                'phase'         => DouConsts::PHASE_PLAY,
                'askMode'       => (int) ($outcome['askMode'] ?? DouConsts::ASK_ROTATE),
                'rule'          => (string) ($outcome['rule'] ?? ''),
                'ruleObj'       => $outcome['ruleObj'] ?? [],
                'landlordIndex' => $landlordSeat,
                'currentIndex'  => $landlordSeat,
                'multiple'      => 1,
                'bottom'        => $outcome['remain'],
                'myCards'       => $cards,
                'players'       => [],
            ];
            if ($fd > 0) {
                $perFd[$fd] = ['code' => 0, 'message' => 'ok', 'data' => $payload];
            }
            if ($pid === $playerId) {
                $selfData = $payload;
            }
        }
        foreach ($perFd as &$item) {
            $item['data']['players'] = $publicSeats;
            // 非本人去掉 myCards 中他人不该看的——每人 payload 已是自己的 cards
        }
        unset($item);
        if ($selfData !== null) {
            $selfData['players'] = $publicSeats;
        }

        // 修正：每人 myCards 只给自己
        foreach ($outcome['players'] as $p) {
            $fd = (int) ($p['fd'] ?? 0);
            if ($fd > 0 && isset($perFd[$fd])) {
                $perFd[$fd]['data']['myCards'] = $p['cards'] ?? [];
            }
        }
        foreach ($outcome['players'] as $p) {
            if ((int) ($p['player_id'] ?? 0) === $playerId) {
                if ($selfData !== null) {
                    $selfData['myCards'] = $p['cards'] ?? [];
                }
                break;
            }
        }

        return [
            'code'       => 0,
            'message'    => 'ok',
            'data'       => $selfData,
            '_broadcast' => ['perFd' => $perFd],
        ];
    }

    /**
     * 三家都不叫后立即重开叫抢局（无需再点准备）
     *
     * @return array{code:int,message:string,data:mixed,_broadcast?:array}|null 人数不足等则 null
     */
    public static function redealGrabRound(int $roomId, int $forPlayerId, ?int $preserveGrabStartedAt = null): ?array
    {
        if ($roomId <= 0) {
            return null;
        }

        $roomService = make(RoomService::class);
        $room = $roomService->getOne(['id' => $roomId], 'id,room_num,rule,status,num,played_count');
        if ($room === null || ! GameRoom::isActiveStatus((int) $room->status)) {
            return null;
        }

        $roomMemberService = make(RoomMemberService::class);
        $members = self::enrichMembers($roomMemberService->getActiveInRoom($roomId));
        if (count($members) < DouConsts::MAX_PLAYERS) {
            return null;
        }

        $readyIds = [];
        foreach ($members as $m) {
            $pid = (int) ($m['player_id'] ?? 0);
            if ($pid > 0) {
                $readyIds[] = $pid;
            }
        }
        $readyIds = array_values(array_unique($readyIds));
        if (count($readyIds) < DouConsts::MAX_PLAYERS) {
            return null;
        }

        try {
            self::assertGoldEnough($roomService, $room->rule ?? null, $members);
        } catch (ApiException) {
            return null;
        }

        $askMode = RoundState::askModeFromRule($room->rule ?? null);

        $gameRoundService = make(GameRoundService::class);
        $newRoundId = (int) $gameRoundService->save([
            'room_id'    => $roomId,
            'player_ids' => implode(',', $readyIds),
            'status'     => GameRound::STATUS_PENDING,
        ]);
        $round = $gameRoundService->getOne(['id' => $newRoundId]);
        if ($round === null) {
            return null;
        }

        $outcome = self::attachRoomRuleToOutcome(
            self::startGrabPhase($room, $round, $readyIds, $members, $askMode, $preserveGrabStartedAt),
            $room
        );
        $packet = self::buildGrabBroadcast($outcome, $forPlayerId > 0 ? $forPlayerId : $readyIds[0]);
        if (is_array($packet['data'] ?? null)) {
            $packet['data']['redraw'] = true;
            $packet['data']['reason'] = '三家都不叫，重新发牌';
            $packet['data']['autoRedeal'] = true;
        }
        foreach ($packet['_broadcast']['perFd'] ?? [] as $fd => $item) {
            if (! is_array($item['data'] ?? null)) {
                continue;
            }
            $packet['_broadcast']['perFd'][$fd]['data']['redraw'] = true;
            $packet['_broadcast']['perFd'][$fd]['data']['reason'] = '三家都不叫，重新发牌';
            $packet['_broadcast']['perFd'][$fd]['data']['autoRedeal'] = true;
        }

        return $packet;
    }

    /** @return list<int> */
    private static function parseIds(?string $ids): array
    {
        return SharedStartGame::parseIds($ids);
    }
}
