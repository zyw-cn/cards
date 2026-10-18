<?php

declare(strict_types=1);

namespace App\Game\Zjh\Action;

use App\Exception\ApiException;
use App\Facades\Log;
use App\Game\Contract\GameActionInterface;
use App\Game\Contract\GameContext;
use App\Game\Zjh\CallBack;
use App\Game\Zjh\Deal;
use App\Game\Zjh\RoundState;
use App\Game\Zjh\Support;
use App\Game\Zjh\ZjhConsts;
use App\Game\Platform\Start\SharedStartGame;
use App\Game\Platform\Timeout\RoomTimeoutService;
use App\Game\Support\GameRecordHooks;
use App\Model\Game\GameRoom;
use App\Model\Game\GameRound;
use App\Service\Game\GamePlayCardService;
use App\Service\Game\GameRoundService;
use App\Service\Game\RoomMemberService;
use App\Service\Game\RoomService;
use App\Service\Game\ZjhChipService;
use App\Service\Game\ZjhSettleService;
use App\Service\Member\MemberService;
use App\Utils\WebSocketSession;
use Hyperf\DbConnection\Db;
use function Hyperf\Support\make;

/**
 * Zjh.StartGame — 准备；满员后发 3 张、收底注，进入下注轮
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
        $playerId = WebSocketSession::uid($body);
        if ($playerId <= 0) {
            return ['code' => 401, 'message' => '请先登录', 'data' => null];
        }

        $roomMemberService = make(RoomMemberService::class);
        $roomService = make(RoomService::class);
        $gameRoundService = make(GameRoundService::class);

        $member = $roomMemberService->getActiveByPlayer($playerId);
        if ($member === null) {
            return ['code' => 400, 'message' => '您还未加入任何房间', 'data' => null];
        }
        $roomId = (int) $member->room_id;

        try {
            $outcome = Db::transaction(function () use (
                $roomId,
                $playerId,
                $roomService,
                $gameRoundService,
                $roomMemberService
            ) {
                $room = $roomService->lockForUpdate(
                    ['id' => $roomId],
                    ['id', 'room_num', 'status', 'played_count', 'total_play_count', 'rule', 'num']
                );
                if ($room === null) {
                    throw new ApiException('房间不存在');
                }
                if (! GameRoom::isActiveStatus((int) $room->status)) {
                    throw new ApiException('本房间已结束');
                }
                $total = (int) ($room->total_play_count ?? 0);
                if ($total > 0 && (int) ($room->played_count ?? 0) >= $total) {
                    throw new ApiException('本房间局数已用完');
                }

                $need = (int) ($room->num ?? 0);
                if ($need < ZjhConsts::MIN_PLAYERS) {
                    $need = ZjhConsts::MIN_PLAYERS;
                }
                if ($need > ZjhConsts::MAX_PLAYERS) {
                    $need = ZjhConsts::MAX_PLAYERS;
                }

                $members = $roomMemberService->getActiveInRoom($roomId);
                if (count($members) < $need) {
                    throw new ApiException('人数未满，满员后才可准备');
                }

                $round = $gameRoundService->getOne([
                    'room_id' => $roomId,
                    ['status', '<', GameRound::STATUS_ENDED],
                ]);

                if ($round === null) {
                    $roundId = $gameRoundService->save([
                        'room_id'    => $roomId,
                        'player_ids' => (string) $playerId,
                        'status'     => GameRound::STATUS_PENDING,
                        'extra'      => '{}',
                    ]);

                    return [
                        'round_id'  => $roundId,
                        'room_num'  => (string) $room->room_num,
                        'ready_ids' => [$playerId],
                        'started'   => false,
                        'need'      => $need,
                    ];
                }

                if ((int) $round->status === GameRound::STATUS_PLAYING) {
                    $state = RoundState::decode($round->extra);
                    $players = [];
                    foreach ($members as $m) {
                        $players[] = [
                            'seat_no'   => (int) $m['seat_no'],
                            'player_id' => (int) $m['player_id'],
                            'nickname'  => (string) ($m['nickname'] ?? ''),
                            'avatar'    => (string) ($m['avatar'] ?? ''),
                            'fd'        => (int) ($m['fd'] ?? 0),
                            'cards'     => array_fill(0, ZjhConsts::CARDS_PER_PLAYER, 'back'),
                            'points'    => null,
                        ];
                    }

                    return [
                        'round_id'  => (int) $round->id,
                        'room_num'  => (string) $room->room_num,
                        'ready_ids' => self::parseIds($round->player_ids),
                        'started'   => true,
                        'already'   => true,
                        'resync'    => true,
                        'need'      => $need,
                        'players'   => $players,
                        'state'     => $state,
                        'ante'      => (float) ($state['unit'] ?? 0),
                        'dealerPid' => RoundState::playerIdOf($state, (int) ($state['dealerSeat'] ?? 0)),
                        'dealerSeat'=> (int) ($state['dealerSeat'] ?? 0),
                        'rule'      => $room->rule ?? null,
                    ];
                }

                $readyIds = self::parseIds($round->player_ids);
                if (! in_array($playerId, $readyIds, true)) {
                    $readyIds[] = $playerId;
                    $gameRoundService->update($round->id, ['player_ids' => implode(',', $readyIds)]);
                }

                if (count($readyIds) < $need) {
                    return [
                        'round_id'  => $round->id,
                        'room_num'  => (string) $room->room_num,
                        'ready_ids' => $readyIds,
                        'started'   => false,
                        'need'      => $need,
                    ];
                }

                if (count($members) < $need) {
                    throw new ApiException('人数不足，无法开始');
                }

                $entryPoints = $roomService->resolveEntryPoints($room->rule ?? null);
                $ante = $roomService->resolveBottomPoints($room->rule ?? null);
                if (bccomp($ante, '0', 2) <= 0) {
                    throw new ApiException('房间底注未配置');
                }

                $seatPids = [];
                foreach ($members as $m) {
                    $seatPids[] = (int) ($m['player_id'] ?? 0);
                }
                $seatPids = array_values(array_filter($seatPids));
                if ($seatPids !== []) {
                    $profiles = make(MemberService::class)->getAll(['id' => $seatPids], 'id,points');
                    $needPoints = bcadd($entryPoints, $ante, 2);
                    foreach ($profiles as $profile) {
                        $pts = bcadd((string) ($profile['points'] ?? '0'), '0', 2);
                        if (bccomp($pts, $needPoints, 2) < 0) {
                            throw new ApiException('有玩家金币不足，无法开始游戏');
                        }
                    }
                }

                $seats = array_map(static fn ($m): int => (int) $m['seat_no'], $members);
                $deal = Deal::dealToSeats($seats);
                $players = [];
                $seatPidMap = [];
                foreach ($members as $m) {
                    $seat = (int) $m['seat_no'];
                    $pid = (int) $m['player_id'];
                    $hand = $deal['hands'][$seat] ?? [];
                    sort($hand);
                    $seatPidMap[(string) $seat] = $pid;
                    $players[] = [
                        'seat_no'   => $seat,
                        'player_id' => $pid,
                        'nickname'  => (string) ($m['nickname'] ?? ''),
                        'avatar'    => (string) ($m['avatar'] ?? ''),
                        'fd'        => (int) ($m['fd'] ?? 0),
                        'cards'     => $hand,
                    ];
                }

                $roundId = (int) $round->id;
                make(GamePlayCardService::class)->recordDeal($roundId, $players);

                // 定庄：首局随机，之后上局赢家当庄；行动从庄家下家开始（庄家最后操作）
                $dealer = self::resolveDealer(
                    (int) ($room->played_count ?? 0),
                    $roomId,
                    $players,
                    $gameRoundService
                );
                $dealerSeat = (int) $dealer['seat_no'];
                $state = [
                    'phase'      => ZjhConsts::PHASE_BET,
                    'pot'        => '0.00',
                    'ante'       => $ante,
                    'unit'       => $ante,
                    'turnSeat'   => 0,
                    'dealerSeat' => $dealerSeat,
                    'looked'     => [],
                    'folded'     => [],
                    'invested'   => [],
                    'seatPids'   => $seatPidMap,
                ];

                $chip = make(ZjhChipService::class);
                $settle = make(ZjhSettleService::class);
                foreach ($players as &$p) {
                    $after = $chip->deduct(
                        (int) $p['player_id'],
                        $ante,
                        sprintf('炸金花底注 房间%d 局%d', $roomId, $roundId)
                    );
                    $p['points'] = (float) $after;
                    $state = $settle->applyChipIn($state, (int) $p['seat_no'], $ante, 'ante');
                }
                unset($p);
                $state['turnSeat'] = RoundState::nextAliveSeat($state, $dealerSeat);

                self::beginGameRecord($roomId, $roundId, $players, $dealerSeat, $ante, $state);

                $dealerPid = (int) $dealer['player_id'];
                $gameRoundService->update($roundId, [
                    'status'    => GameRound::STATUS_PLAYING,
                    'master_id' => $dealerPid,
                    'multiple'  => 1,
                    'extra'     => RoundState::encode($state),
                ]);
                if ((int) $room->status !== GameRoom::STATUS_PLAYING) {
                    $roomService->update($roomId, ['status' => GameRoom::STATUS_PLAYING]);
                }
                $roomMemberService->markPlayingForRoom($roomId);

                return [
                    'round_id' => $roundId,
                    'room_num' => (string) $room->room_num,
                    'ready_ids'=> $readyIds,
                    'started'  => true,
                    'need'     => $need,
                    'players'  => $players,
                    'state'    => $state,
                    'ante'     => $ante,
                    'dealerPid'=> $dealerPid,
                    'dealerSeat'=> $dealerSeat,
                    'rule'     => $room->rule ?? null,
                ];
            });
        } catch (ApiException $e) {
            return ['code' => 400, 'message' => $e->getMessage(), 'data' => null];
        } catch (\Throwable $e) {
            Log::error('炸金花开始游戏失败', [
                'room_id'   => $roomId,
                'player_id' => $playerId,
                'error'     => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);

            return ['code' => 500, 'message' => '开始游戏失败，请重试', 'data' => null];
        }

        make(RoomTimeoutService::class)->armGameTimeout($roomId);

        // 未满员：全桌广播准备态（readyIds），他人可见准备 tip
        if (empty($outcome['started'])) {
            return SharedStartGame::buildWaitBroadcast(
                $outcome,
                $roomId,
                $roomMemberService,
                CallBack::WAIT_START,
                ['need' => (int) ($outcome['need'] ?? 0)]
            );
        }

        $table = Support::publicTable(
            $outcome['state'],
            $outcome['room_num'],
            (int) $outcome['round_id'],
            $outcome['rule'] ?? null
        );
        $publicSeats = [];
        $perFd = [];
        $selfData = null;
        $resync = ! empty($outcome['resync']) || ! empty($outcome['already']);
        foreach ($outcome['players'] as $p) {
            $pfd = (int) ($p['fd'] ?? 0);
            $pid = (int) $p['player_id'];
            // 开局只发暗牌张数，看牌后才下发 myCards
            $cardCount = count($p['cards'] ?? []);
            $seatRow = [
                'seat_no'    => (int) $p['seat_no'],
                'player_id'  => $pid,
                'nickname'   => (string) ($p['nickname'] ?? ''),
                'avatar'     => (string) ($p['avatar'] ?? ''),
                'cardsCount' => $cardCount,
            ];
            if (isset($p['points']) && $p['points'] !== null) {
                $seatRow['points'] = (float) $p['points'];
                $seatRow['gold'] = (float) $p['points'];
            }
            $publicSeats[] = $seatRow;
            $payloadData = array_merge($table, [
                'callback'       => CallBack::START_GAME,
                'ante'           => (float) $outcome['ante'],
                'dealerPlayerId' => (int) ($outcome['dealerPid'] ?? 0),
                'myCards'        => [],
                'players'        => [],
                'resync'         => $resync,
            ]);
            if ($pfd > 0) {
                $perFd[$pfd] = ['code' => 0, 'message' => 'ok', 'data' => $payloadData];
            }
            if ($pid === $playerId) {
                $selfData = $payloadData;
            }
        }
        foreach ($perFd as &$item) {
            $item['data']['players'] = $publicSeats;
        }
        unset($item);
        if ($selfData !== null) {
            $selfData['players'] = $publicSeats;
        }

        // 迟到准备/漏推送：只回给本人桌面快照，不再广播以免他人重播发牌
        if ($resync) {
            return [
                'code'    => 0,
                'message' => 'ok',
                'data'    => $selfData ?? array_merge($table, [
                    'callback' => CallBack::START_GAME,
                    'myCards'  => [],
                    'players'  => $publicSeats,
                    'resync'   => true,
                ]),
            ];
        }

        return [
            'code'       => 0,
            'message'    => 'ok',
            'data'       => $selfData ?? array_merge($table, [
                'callback' => CallBack::START_GAME,
                'myCards'  => [],
                'players'  => $publicSeats,
            ]),
            '_broadcast' => ['perFd' => $perFd],
        ];
    }

    /**
     * @param list<array{seat_no:int,player_id:int,nickname?:string,avatar?:string,cards?:list<string>}> $players
     * @param array<string, mixed> $state
     */
    private static function beginGameRecord(
        int $roomId,
        int $roundId,
        array $players,
        int $dealerSeat,
        string $ante,
        array $state
    ): void {
        try {
            $meta = GameRecordHooks::loadRoomMeta($roomId);
            if ($meta === null || $roundId <= 0) {
                return;
            }
            $seats = [];
            $recordPlayers = [];
            foreach ($players as $p) {
                $seat = (int) ($p['seat_no'] ?? 0);
                $tiles = array_values(is_array($p['cards'] ?? null) ? $p['cards'] : []);
                $seats[] = [
                    'seat'     => $seat,
                    'memberId' => (int) ($p['player_id'] ?? 0),
                    'nickname' => (string) ($p['nickname'] ?? ''),
                    'avatar'   => (string) ($p['avatar'] ?? ''),
                    'tiles'    => $tiles,
                ];
                $recordPlayers[] = [
                    'memberId' => (int) ($p['player_id'] ?? 0),
                    'seatNo'   => $seat,
                    'nickname' => (string) ($p['nickname'] ?? ''),
                    'avatar'   => (string) ($p['avatar'] ?? ''),
                    'isBanker' => $seat === $dealerSeat,
                ];
            }
            GameRecordHooks::beginFromRoom($meta, $roundId, $recordPlayers, [
                'seats'      => $seats,
                'dealerSeat' => $dealerSeat,
                'ext'        => [
                    'ante' => $ante,
                    'unit' => $state['unit'] ?? $ante,
                    'pot'  => $state['pot'] ?? '0.00',
                ],
            ]);
        } catch (\Throwable) {
            // 录像失败不影响牌局
        }
    }

    /**
     * 定庄：第一局随机；之后取上局 winner_user_ids 仍在桌者，否则随机
     *
     * @param list<array{seat_no:int, player_id:int}> $players
     * @return array{seat_no:int, player_id:int}
     */
    private static function resolveDealer(
        int $playedCount,
        int $roomId,
        array $players,
        GameRoundService $gameRoundService
    ): array {
        usort($players, static fn (array $a, array $b): int => (int) $a['seat_no'] <=> (int) $b['seat_no']);
        if ($players === []) {
            throw new ApiException('没有可定庄的玩家');
        }
        if ($playedCount <= 0) {
            return $players[array_rand($players)];
        }

        $previous = $gameRoundService->getAll(
            ['room_id' => $roomId, 'status' => GameRound::STATUS_ENDED],
            'id,winner_user_ids',
            ['id' => 'desc']
        );
        $last = $previous[0] ?? null;
        if ($last === null) {
            return $players[array_rand($players)];
        }
        $winnerIds = self::parseIds(
            is_array($last)
                ? (string) ($last['winner_user_ids'] ?? '')
                : (string) ($last->winner_user_ids ?? '')
        );
        foreach ($winnerIds as $winnerId) {
            foreach ($players as $player) {
                if ((int) $player['player_id'] === $winnerId) {
                    return $player;
                }
            }
        }

        return $players[array_rand($players)];
    }

    /** @return list<int> */
    private static function parseIds(?string $ids): array
    {
        $list = array_map('intval', array_filter(explode(',', (string) $ids), static fn ($v) => $v !== ''));

        return array_values(array_unique($list));
    }
}
