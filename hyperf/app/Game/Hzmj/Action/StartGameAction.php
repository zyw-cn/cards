<?php

declare(strict_types=1);

namespace App\Game\Hzmj\Action;

use App\Exception\ApiException;
use App\Facades\Log;
use App\Game\Contract\GameActionInterface;
use App\Game\Contract\GameContext;
use App\Game\Hzmj\CallBack;
use App\Game\Hzmj\DealBootstrap;
use App\Game\Hzmj\HzmjConsts;
use App\Game\Hzmj\RoundState;
use App\Game\Platform\Start\SharedStartGame;
use App\Model\Game\GameRound;
use App\Service\Game\GameRoundService;
use App\Service\Game\RoomMemberService;
use App\Service\Game\RoomService;
use Hyperf\DbConnection\Db;
use function Hyperf\Support\make;

/**
 * Hzmj.StartGame — 满员准备后进抢庄；定庄后由 GrabBanker 发牌
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
                $room = SharedStartGame::lockRoomForStart($roomService, $roomId, ['num']);

                $need = RoundState::requiredPlayers((int) ($room->num ?? HzmjConsts::MAX_PLAYERS));
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
                        'need'      => $need,
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

                if ((int) $round->status === GameRound::STATUS_PLAYING
                    && (int) ($round->master_id ?? 0) > 0
                ) {
                    return [
                        'mode'     => 'started',
                        'round_id' => $round->id,
                        'room_num' => (string) $room->room_num,
                    ];
                }

                $readyIds = self::parseIds($round->player_ids);
                if (! in_array($playerId, $readyIds, true)) {
                    $readyIds[] = $playerId;
                    $gameRoundService->update($round->id, ['player_ids' => implode(',', $readyIds)]);
                }

                if (count($readyIds) < $need) {
                    return self::attachRoomRuleToOutcome([
                        'mode'      => 'wait',
                        'round_id'  => $round->id,
                        'room_num'  => (string) $room->room_num,
                        'ready_ids' => $readyIds,
                        'need'      => $need,
                    ], $room);
                }

                if ($members === []) {
                    $members = self::enrichMembers($roomMemberService->getActiveInRoom($roomId));
                }
                if (count($members) < $need) {
                    return self::attachRoomRuleToOutcome([
                        'mode'      => 'wait',
                        'round_id'  => $round->id,
                        'room_num'  => (string) $room->room_num,
                        'ready_ids' => $readyIds,
                        'need'      => $need,
                    ], $room);
                }

                // 仅取前 need 个座位（按 seat_no）参与本局
                usort($members, static fn (array $a, array $b): int => (int) $a['seat_no'] <=> (int) $b['seat_no']);
                $members = array_slice($members, 0, $need);

                // 抢庄中再次点开始：提示即可
                $extra = RoundState::decode($round->extra ?? null);
                if ((int) $round->status === GameRound::STATUS_PLAYING
                    && (int) ($round->master_id ?? 0) <= 0
                    && ($extra['phase'] ?? '') === HzmjConsts::PHASE_GRAB
                ) {
                    return self::attachRoomRuleToOutcome([
                        'mode'      => 'grabbing',
                        'round_id'  => (int) $round->id,
                        'room_num'  => (string) $room->room_num,
                        'ready_ids' => $readyIds,
                        'need'      => $need,
                        'players'   => $members,
                    ], $room);
                }

                return self::attachRoomRuleToOutcome(
                    self::enterGrab($room, $round, $readyIds, $members, $need, $gameRoundService),
                    $room
                );
            });
        } catch (ApiException $e) {
            return ['code' => 400, 'message' => $e->getMessage(), 'data' => null];
        } catch (\Throwable $e) {
            Log::error('红中麻将开始游戏失败', [
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

        if (($outcome['mode'] ?? '') === 'grabbing') {
            return ['code' => 400, 'message' => '正在抢庄，请选择倍数', 'data' => null];
        }

        if (($outcome['mode'] ?? '') === 'wait') {
            return self::buildWaitBroadcast($outcome, $roomId, $roomMemberService);
        }

        if (($outcome['mode'] ?? '') === 'grab') {
            return self::buildGrabBroadcast($outcome);
        }

        return self::buildDealBroadcast($outcome, $playerId);
    }

    private static function pickOpenRoundRow(int $roomId, GameRoundService $gameRoundService): ?object
    {
        return SharedStartGame::pickOpenRoundRow(
            $roomId,
            $gameRoundService,
            static function (array|object $round): bool {
                // 已定庄（出牌中）
                if ((int) SharedStartGame::roundField($round, 'master_id') > 0) {
                    return true;
                }
                // 抢庄中 master_id=0，仍算进行中；否则会被误标 ENDED，下一局开不起来
                $phase = (string) (RoundState::decode(
                    SharedStartGame::roundField($round, 'extra')
                )['phase'] ?? '');

                return $phase === HzmjConsts::PHASE_GRAB;
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
            ['need' => (int) ($outcome['need'] ?? HzmjConsts::MAX_PLAYERS)]
        );
    }

    /**
     * @param array<string, mixed> $outcome
     * @return array<string, mixed>
     */
    private static function attachRoomRuleToOutcome(array $outcome, object $room): array
    {
        $payload = RoundState::rulePayloadFromRule($room->rule ?? null);
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
     * @return list<array<string,mixed>>
     */
    public static function enrichMembersPublic(array $members): array
    {
        return self::enrichMembers($members);
    }

    /**
     * @param list<array<string,mixed>> $members
     */
    private static function assertGoldEnough(RoomService $roomService, ?string $rule, array $members): void
    {
        SharedStartGame::assertGoldEnough($roomService, $rule, $members);
    }

    /**
     * 满员后进入抢庄（不发牌、不掷骰）
     *
     * @param list<int> $readyIds
     * @param list<array<string,mixed>> $members
     * @return array<string, mixed>
     */
    private static function enterGrab(
        $room,
        $round,
        array $readyIds,
        array $members,
        int $need,
        GameRoundService $gameRoundService,
    ): array {
        $state = [
            'phase'      => HzmjConsts::PHASE_GRAB,
            'dealerSeat' => -1,
            'curSeat'    => -1,
            'kingTiles'  => [HzmjConsts::KING_TILE],
            'luckyNum'   => RoundState::luckyNumFromRule($room->rule ?? null),
            'maxPlayers' => $need,
            'diceList'   => [],
            'grabs'      => [],
            'bankBeishu' => 1,
            'df'         => RoundState::dfFromRule($room->rule ?? null),
            // 跨局累计分：从上一局已结束局继承
            'totalScores'=> self::prevTotalScores((int) $room->id, $gameRoundService),
        ];

        $gameRoundService->update((int) $round->id, [
            'status'     => GameRound::STATUS_PLAYING,
            'master_id'  => 0,
            'player_ids' => implode(',', $readyIds),
            'extra'      => RoundState::encode($state),
            'multiple'   => 1,
        ]);

        // 房间进入游戏中
        make(RoomService::class)->update((int) $room->id, [
            'status' => \App\Model\Game\GameRoom::STATUS_PLAYING,
        ]);
        make(RoomMemberService::class)->markPlayingForRoom((int) $room->id);

        return [
            'mode'       => 'grab',
            'round_id'   => (int) $round->id,
            'room_num'   => (string) $room->room_num,
            'ready_ids'  => $readyIds,
            'players'    => $members,
            'need'       => $need,
            'luckyNum'   => (int) ($state['luckyNum'] ?? 2),
            'maxPlayers' => $need,
            'phase'      => HzmjConsts::PHASE_GRAB,
        ];
    }

    /**
     * 定庄后发牌（无骰子）
     *
     * @param list<int> $readyIds
     * @param list<array<string,mixed>> $members
     * @return array<string, mixed>
     */
    public static function startDeal(
        $room,
        $round,
        array $readyIds,
        array $members,
        int $need,
        GameRoundService $gameRoundService,
        ?int $dealerSeat = null,
        int $bankBeishu = 1,
    ): array {
        if ($dealerSeat === null || $dealerSeat < 0) {
            $dealerSeat = self::resolveDealerSeat($room, $members, $gameRoundService);
        }
        $dealt = DealBootstrap::dealToMembers($members, $dealerSeat);
        $dealerPid = DealBootstrap::pidOfSeat($dealt['players'], $dealerSeat);

        $prevExtra = RoundState::decode($round->extra ?? null);
        $state = [
            'phase'      => HzmjConsts::PHASE_PLAYING,
            'dealerSeat' => $dealerSeat,
            'curSeat'    => $dealerSeat,
            'kingTiles'  => [HzmjConsts::KING_TILE],
            'luckyNum'   => RoundState::luckyNumFromRule($room->rule ?? null),
            'maxPlayers' => $need,
            'diceList'   => [],
            'grabs'      => is_array($prevExtra['grabs'] ?? null) ? $prevExtra['grabs'] : [],
            'bankBeishu' => max(1, $bankBeishu),
            'df'         => RoundState::dfFromRule($room->rule ?? null),
            'totalScores'=> is_array($prevExtra['totalScores'] ?? null) ? $prevExtra['totalScores'] : [],
        ];

        $players = DealBootstrap::persistDeal(
            (int) $room->id,
            (int) $round->id,
            $dealt['players'],
            $state,
            $dealerPid,
            $dealerSeat,
            $dealt['wall']
        );

        $gameRoundService->update((int) $round->id, [
            'master_id' => $dealerPid,
            'multiple'  => max(1, $bankBeishu),
        ]);

        $extra = RoundState::decode(
            make(GameRoundService::class)->getOne(['id' => (int) $round->id], 'extra')?->extra ?? null
        );

        return [
            'mode'       => 'deal',
            'round_id'   => (int) $round->id,
            'room_num'   => (string) $room->room_num,
            'ready_ids'  => $readyIds,
            'players'    => $players,
            'dealerSeat' => $dealerSeat,
            'curSeat'    => $dealerSeat,
            'leftTileNum'=> count($dealt['wall']),
            'kingTiles'  => [HzmjConsts::KING_TILE],
            'waitOps'    => $extra['waitOps'] ?? [],
            'luckyNum'   => (int) ($state['luckyNum'] ?? 2),
            'maxPlayers' => $need,
            'diceList'   => [],
            'bankBeishu' => max(1, $bankBeishu),
            'df'         => (float) ($state['df'] ?? 1),
        ];
    }

    /**
     * 进抢庄：全员同包。用 resolveMemberFd，避免库内 fd 过期导致其他人收不到。
     *
     * @param array<string, mixed> $outcome
     * @return array{code: int, message: string, data: mixed, _broadcast?: array}
     */
    private static function buildGrabBroadcast(array $outcome): array
    {
        $roomMemberService = make(RoomMemberService::class);
        $publicSeats = [];
        $targetFds = [];
        foreach ($outcome['players'] as $p) {
            $pid = (int) ($p['player_id'] ?? 0);
            $seat = (int) ($p['seat_no'] ?? 0);
            $publicSeats[] = [
                'seat_no'    => $seat,
                'seatNo'     => $seat,
                'player_id'  => $pid,
                'playerId'   => $pid,
                'nickname'   => (string) ($p['nickname'] ?? ''),
                'avatar'     => (string) ($p['avatar'] ?? ''),
                'avatar_url' => (string) ($p['avatar_url'] ?? ''),
                'points'     => (float) ($p['points'] ?? 0),
                'gold'       => (float) ($p['points'] ?? $p['gold'] ?? 0),
            ];
            $fd = $roomMemberService->resolveMemberFd($p);
            if ($fd > 0) {
                $targetFds[] = $fd;
            }
        }

        $data = [
            'callback'      => CallBack::GRAB,
            'roomId'        => $outcome['room_num'],
            'roundId'       => $outcome['round_id'],
            'phase'         => HzmjConsts::PHASE_GRAB,
            'rule'          => (string) ($outcome['rule'] ?? ''),
            'ruleObj'       => $outcome['ruleObj'] ?? [],
            'grabs'         => [],
            'luckyNum'      => (int) ($outcome['luckyNum'] ?? 2),
            'maxPlayers'    => (int) ($outcome['maxPlayers'] ?? HzmjConsts::MAX_PLAYERS),
            'players'       => $publicSeats,
            'seats'         => $publicSeats,
            'countdown'     => HzmjConsts::GRAB_TIMEOUT,
            'grabMultiples' => HzmjConsts::GRAB_MULTIPLES,
            'df'            => RoundState::dfFromRule(
                is_string($outcome['rule'] ?? null) ? $outcome['rule'] : null
            ),
        ];

        return [
            'code'       => 0,
            'message'    => 'ok',
            'data'       => $data,
            '_broadcast' => [
                // WS 入口会自动排除请求者 fd，这里传全员即可
                'targetFds' => array_values(array_unique($targetFds)),
                'payload'   => ['code' => 0, 'message' => 'ok', 'data' => $data],
            ],
        ];
    }

    /**
     * @param list<array<string,mixed>> $members
     */
    private static function resolveDealerSeat($room, array $members, GameRoundService $gameRoundService): int
    {
        $seats = array_map(static fn ($m): int => (int) $m['seat_no'], $members);
        sort($seats);
        $fallback = $seats[0] ?? 0;

        // 上一局已结束：沿用其 dealerSeat（胡牌后已写成赢家）
        // 注意：BaseDao::getOne 第 3 参是 with 关联，不是 orderBy；须用 getAll 排序取最新一局
        $prevRows = $gameRoundService->getAll(
            [
                'room_id' => (int) $room->id,
                'status'  => GameRound::STATUS_ENDED,
            ],
            'id,extra,winner_user_ids',
            ['id' => 'desc']
        );
        $prev = $prevRows[0] ?? null;
        if ($prev === null) {
            return $fallback;
        }
        $extraRaw = is_array($prev) ? ($prev['extra'] ?? null) : ($prev->extra ?? null);
        $extra = RoundState::decode($extraRaw);
        $seat = (int) ($extra['dealerSeat'] ?? -1);
        if (in_array($seat, $seats, true)) {
            return $seat;
        }

        return $fallback;
    }

    /** @return array<string, int|float> */
    private static function prevTotalScores(int $roomId, GameRoundService $gameRoundService): array
    {
        $prevRows = $gameRoundService->getAll(
            [
                'room_id' => $roomId,
                'status'  => GameRound::STATUS_ENDED,
            ],
            'id,extra',
            ['id' => 'desc']
        );
        $prev = $prevRows[0] ?? null;
        if ($prev === null) {
            return [];
        }
        $extraRaw = is_array($prev) ? ($prev['extra'] ?? null) : ($prev->extra ?? null);
        $totals = RoundState::decode($extraRaw)['totalScores'] ?? [];

        return is_array($totals) ? $totals : [];
    }

    /**
     * 定庄发牌广播（GrabBankerAction 复用）
     *
     * @param array<string, mixed> $outcome
     * @return array{code: int, message: string, data: mixed, _broadcast?: array}
     */
    public static function buildDealBroadcastPublic(array $outcome, int $playerId): array
    {
        return self::buildDealBroadcast($outcome, $playerId);
    }

    /**
     * @param array<string, mixed> $outcome
     * @return array{code: int, message: string, data: mixed, _broadcast?: array}
     */
    private static function buildDealBroadcast(array $outcome, int $playerId): array
    {
        $roomMemberService = make(RoomMemberService::class);
        $publicSeats = [];
        $perFd = [];
        $selfData = null;
        $dealerSeat = (int) $outcome['dealerSeat'];
        $curSeat = (int) $outcome['curSeat'];
        $leftTileNum = (int) $outcome['leftTileNum'];
        $kingTiles = $outcome['kingTiles'] ?? [HzmjConsts::KING_TILE];
        $waitOpsMap = is_array($outcome['waitOps'] ?? null) ? $outcome['waitOps'] : [];

        foreach ($outcome['players'] as $p) {
            $fd = $roomMemberService->resolveMemberFd($p);
            $pid = (int) ($p['player_id'] ?? 0);
            $seat = (int) ($p['seat_no'] ?? 0);
            $cards = $p['cards'] ?? [];
            $tileCount = is_array($cards) ? count($cards) : 0;
            $publicSeats[] = [
                'seat_no'    => $seat,
                'seatNo'     => $seat,
                'player_id'  => $pid,
                'nickname'   => (string) ($p['nickname'] ?? ''),
                'avatar'     => (string) ($p['avatar'] ?? ''),
                'avatar_url' => (string) ($p['avatar_url'] ?? ''),
                'tileCount'  => $tileCount,
                'cardsCount' => $tileCount,
                'points'     => (float) ($p['points'] ?? 0),
                'gold'       => (float) ($p['points'] ?? $p['gold'] ?? 0),
            ];

            $myWait = $waitOpsMap[(string) $seat] ?? [];
            $payload = [
                'callback'   => CallBack::START_GAME,
                'roomId'     => $outcome['room_num'],
                'roundId'    => $outcome['round_id'],
                'phase'      => HzmjConsts::PHASE_PLAYING,
                'rule'       => (string) ($outcome['rule'] ?? ''),
                'ruleObj'    => $outcome['ruleObj'] ?? [],
                'dealerSeat' => $dealerSeat,
                'curSeat'    => $curSeat,
                'leftTileNum'=> $leftTileNum,
                'kingTiles'  => $kingTiles,
                'discards'   => new \stdClass(),
                'ups'        => new \stdClass(),
                'myTiles'    => array_values(array_map('intval', is_array($cards) ? $cards : [])),
                'myWaitOps'  => array_values(array_map('intval', is_array($myWait) ? $myWait : [])),
                'seats'      => [],
                'players'    => [],
                'luckyNum'   => (int) ($outcome['luckyNum'] ?? 2),
                'maxPlayers' => (int) ($outcome['maxPlayers'] ?? HzmjConsts::MAX_PLAYERS),
                'diceList'   => [],
                'bankBeishu' => max(1, (int) ($outcome['bankBeishu'] ?? $outcome['grabMultiple'] ?? 1)),
                'grabMultiple' => max(1, (int) ($outcome['bankBeishu'] ?? $outcome['grabMultiple'] ?? 1)),
                'bankerSeat' => $dealerSeat,
                'needRandom' => ! empty($outcome['needRandom']),
                'isSystemBanker' => ! empty($outcome['isSystemBanker']),
                'candidates' => self::publicGrabCandidates($outcome['candidates'] ?? []),
                'grabs'      => array_values(is_array($outcome['grabs'] ?? null) ? $outcome['grabs'] : []),
                'df'         => (float) (($outcome['df'] ?? 1) > 0 ? ($outcome['df'] ?? 1) : 1),
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
            $item['data']['seats'] = $publicSeats;
        }
        unset($item);
        if ($selfData !== null) {
            $selfData['players'] = $publicSeats;
            $selfData['seats'] = $publicSeats;
        }

        return [
            'code'       => 0,
            'message'    => 'ok',
            'data'       => $selfData,
            '_broadcast' => ['perFd' => $perFd],
        ];
    }

    /**
     * @param mixed $raw
     * @return list<array{seat:int, playerId:int, multiple:int}>
     */
    private static function publicGrabCandidates($raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $c) {
            if (! is_array($c)) {
                continue;
            }
            $out[] = [
                'seat'     => (int) ($c['seat'] ?? 0),
                'playerId' => (int) ($c['player_id'] ?? $c['playerId'] ?? 0),
                'multiple' => (int) ($c['multiple'] ?? 0),
            ];
        }

        return $out;
    }

    /** @return list<int> */
    private static function parseIds(?string $ids): array
    {
        return SharedStartGame::parseIds($ids);
    }
}
