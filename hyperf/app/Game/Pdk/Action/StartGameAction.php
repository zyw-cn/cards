<?php

declare(strict_types=1);

namespace App\Game\Pdk\Action;

use App\Exception\ApiException;
use App\Facades\Log;
use App\Game\Contract\GameActionInterface;
use App\Game\Contract\GameContext;
use App\Game\Pdk\CallBack;
use App\Game\Pdk\DealBootstrap;
use App\Game\Pdk\PdkConsts;
use App\Game\Pdk\RoundState;
use App\Game\Platform\Start\SharedStartGame;
use App\Model\Game\GameRound;
use App\Service\Game\GameRoundService;
use App\Service\Game\RoomMemberService;
use App\Service\Game\RoomService;
use App\Utils\WebSocketSession;
use Hyperf\DbConnection\Db;
use function Hyperf\Support\make;

/**
 * Pdk.StartGame — 满 3 准备后发牌，红桃 3 先出
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

                if (count($readyIds) < PdkConsts::MAX_PLAYERS) {
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

                return self::attachRoomRuleToOutcome(
                    self::startDeal($room, $round, $readyIds, $members),
                    $room
                );
            });
        } catch (ApiException $e) {
            return ['code' => 400, 'message' => $e->getMessage(), 'data' => null];
        } catch (\Throwable $e) {
            Log::error('跑得快开始游戏失败', [
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

        return self::buildDealBroadcast($outcome, $playerId);
    }

    /**
     * @return object|null
     */
    private static function pickOpenRoundRow(int $roomId, GameRoundService $gameRoundService): ?object
    {
        return SharedStartGame::pickOpenRoundRow(
            $roomId,
            $gameRoundService,
            static fn (array|object $round): bool => (int) SharedStartGame::roundField($round, 'master_id') > 0
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
            CallBack::WAIT_START
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
        $outcome['playedCount'] = (int) ($room->played_count ?? 0);
        $outcome['totalCount'] = (int) ($room->total_play_count ?? 0);

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
     * @return array<string, mixed>
     */
    private static function startDeal($room, $round, array $readyIds, array $members): array
    {
        $dealt = DealBootstrap::dealToMembers($members);
        $firstSeat = $dealt['firstSeat'];
        $firstPid = DealBootstrap::pidOfSeat($dealt['players'], $firstSeat);
        $state = [
            'phase'        => PdkConsts::PHASE_PLAY,
            'firstSeat'    => $firstSeat,
            'bombCount'    => 0,
            'bombs'        => [],
            'spade3Played' => false,
        ];

        $players = DealBootstrap::persistDeal(
            (int) $room->id,
            (int) $round->id,
            $dealt['players'],
            $state,
            $firstPid,
            $firstSeat
        );

        return [
            'mode'         => 'deal',
            'round_id'     => (int) $round->id,
            'room_num'     => (string) $room->room_num,
            'ready_ids'    => $readyIds,
            'players'      => $players,
            'firstSeat'    => $firstSeat,
            'firstPid'     => $firstPid,
            'currentIndex' => $firstSeat,
        ];
    }

    /**
     * @param array<string, mixed> $outcome
     * @return array{code: int, message: string, data: mixed, _broadcast?: array}
     */
    private static function buildDealBroadcast(array $outcome, int $playerId): array
    {
        $publicSeats = [];
        $perFd = [];
        $selfData = null;
        $firstSeat = (int) $outcome['firstSeat'];

        foreach ($outcome['players'] as $p) {
            $pid = (int) ($p['player_id'] ?? 0);
            $fd = (int) ($p['fd'] ?? 0);
            $liveFd = $pid > 0 ? WebSocketSession::getFdByUid($pid) : 0;
            if ($liveFd > 0) {
                $fd = $liveFd;
            }
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
                'callback'     => CallBack::START_GAME,
                'roomId'       => $outcome['room_num'],
                'roundId'      => $outcome['round_id'],
                'phase'        => PdkConsts::PHASE_PLAY,
                'rule'         => (string) ($outcome['rule'] ?? ''),
                'ruleObj'      => $outcome['ruleObj'] ?? [],
                'firstIndex'   => $firstSeat,
                'currentIndex' => $firstSeat,
                'mustSpade3'   => true,
                'mustBeat'     => true,
                'currentRound' => (int) ($outcome['playedCount'] ?? 0) + 1,
                'totalCount'   => (int) ($outcome['totalCount'] ?? 0),
                'myCards'      => $cards,
                'players'      => [],
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
            $pid = (int) ($p['player_id'] ?? 0);
            $fd = (int) ($p['fd'] ?? 0);
            $liveFd = $pid > 0 ? WebSocketSession::getFdByUid($pid) : 0;
            if ($liveFd > 0) {
                $fd = $liveFd;
            }
            if ($fd > 0 && isset($perFd[$fd])) {
                $perFd[$fd]['data']['myCards'] = $p['cards'] ?? [];
            }
            if ($pid === $playerId && $selfData !== null) {
                $selfData['myCards'] = $p['cards'] ?? [];
            }
        }

        return [
            'code'       => 0,
            'message'    => 'ok',
            'data'       => $selfData,
            '_broadcast' => ['perFd' => $perFd],
        ];
    }

    /** @return list<int> */
    private static function parseIds(?string $ids): array
    {
        return SharedStartGame::parseIds($ids);
    }
}
