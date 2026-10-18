<?php

declare(strict_types=1);

namespace App\Game\Niu\Action;

use App\Exception\ApiException;
use App\Facades\Log;
use App\Game\Contract\GameActionInterface;
use App\Game\Contract\GameContext;
use App\Game\Niu\CallBack;
use App\Game\Niu\Deal;
use App\Game\Niu\NiuConsts;
use App\Game\Niu\RoundPhase;
use App\Game\Platform\Start\SharedStartGame;
use App\Game\Platform\Timeout\RoomTimeoutService;
use App\Game\Support\GameRecordHooks;
use App\Model\Game\GameRoom;
use App\Model\Game\GameRound;
use App\Service\Game\GamePlayCardService;
use App\Service\Game\GameRoundService;
use App\Service\Game\RoomMemberService;
use App\Service\Game\RoomService;
use App\Service\Member\MemberService;
use App\Utils\WebSocketSession;
use Hyperf\DbConnection\Db;
use function Hyperf\Support\make;

/**
 * Niu.StartGame — 人数满自动开局（保留手动调用兼容旧客户端）
 *
 * Room.Join 满员后传 _auto_full，直接按在座成员开局并发牌；
 * 每人只收到自己的 myCards，前端未看牌时亮前 2 张、其余 3 张牌背。
 * 开局所需人数 = room.num（建房时写入，已钳制在 2~10）。
 *
 * body：无特殊字段（uid/fd 由会话注入）
 * 推送：未满 → waitstart；满员 → startgame + phase=grab
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
        $autoFull = ! empty($body['_auto_full']);

        // 根据 uid 定位所在房间
        $member = $roomMemberService->getActiveByPlayer($playerId);
        if ($member === null) {
            return ['code' => 400, 'message' => '您还未加入任何房间', 'data' => null];
        }
        $roomId = (int) $member->room_id;

        // ============================================================
        // 事务内：收集准备 / 满员则切到抢庄
        // ============================================================
        try {
            $outcome = Db::transaction(function () use (
                $roomId,
                $playerId,
                $roomService,
                $gameRoundService,
                $roomMemberService,
                $autoFull
            ) {
                // 锁住房间行，串行化同一房间的并发「开始」
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

                // 本房开局人数：建房时的 num，夹在 [MIN, MAX]
                $need = (int) ($room->num ?? 0);
                if ($need < NiuConsts::MIN_PLAYERS) {
                    $need = NiuConsts::MIN_PLAYERS;
                }
                if ($need > NiuConsts::MAX_PLAYERS) {
                    $need = NiuConsts::MAX_PLAYERS;
                }

                $members = $autoFull ? $roomMemberService->getActiveInRoom($roomId) : [];
                $autoReadyIds = array_values(array_filter(array_map(
                    static fn ($m): int => (int) ($m['player_id'] ?? 0),
                    $members
                )));
                if ($autoFull && count($autoReadyIds) < $need) {
                    throw new ApiException('人数不足，无法开始');
                }

                // 取该房间未结束的局（status < 2）；注意 getOne 不支持 in 条件
                $round = $gameRoundService->getOne([
                    'room_id' => $roomId,
                    ['status', '<', GameRound::STATUS_ENDED],
                ]);

                // 无进行中的局 → 新建 PENDING；自动开局时所有在座玩家直接确认
                if ($round === null) {
                    $initialReadyIds = $autoFull ? $autoReadyIds : [$playerId];
                    $roundId = $gameRoundService->save([
                        'room_id'    => $roomId,
                        'player_ids' => implode(',', $initialReadyIds),
                        'status'     => GameRound::STATUS_PENDING,
                        'extra'      => RoundPhase::encode(NiuConsts::PHASE_GRAB),
                    ]);

                    if (! $autoFull) {
                        return [
                            'round_id'  => $roundId,
                            'room_num'  => (string) $room->room_num,
                            'ready_ids' => $initialReadyIds,
                            'started'   => false,
                            'need'      => $need,
                        ];
                    }
                    $round = $gameRoundService->getOne(['id' => $roundId]);
                    if ($round === null) {
                        throw new ApiException('创建牌局失败');
                    }
                }

                // 已进入 PLAYING（抢庄/下注/亮牌中）→ 拒绝重复开局
                if ((int) $round->status === GameRound::STATUS_PLAYING) {
                    return [
                        'round_id'  => $round->id,
                        'room_num'  => (string) $room->room_num,
                        'ready_ids' => self::parseIds($round->player_ids),
                        'started'   => true,
                        'already'   => true,
                        'need'      => $need,
                    ];
                }

                // PENDING：把当前玩家并入确认列表
                $readyIds = $autoFull ? $autoReadyIds : self::parseIds($round->player_ids);
                if (! in_array($playerId, $readyIds, true)) {
                    $readyIds[] = $playerId;
                }
                $gameRoundService->update($round->id, ['player_ids' => implode(',', $readyIds)]);

                // 确认人数未达 room.num → 继续等待
                if (count($readyIds) < $need) {
                    return [
                        'round_id'  => $round->id,
                        'room_num'  => (string) $room->room_num,
                        'ready_ids' => $readyIds,
                        'started'   => false,
                        'need'      => $need,
                    ];
                }

                if ($members === []) {
                    $members = $roomMemberService->getActiveInRoom($roomId);
                }
                $members = SharedStartGame::enrichMembers($members);
                if (count($members) < $need) {
                    throw new ApiException('人数不足，无法开始');
                }

                // 开局前二次校验入场金币（防止入座后金币被扣光）
                $entryPoints = $roomService->resolveEntryPoints($room->rule ?? null);
                if (bccomp($entryPoints, '0', 2) > 0) {
                    $seatPids = array_values(array_filter(array_map(
                        static fn ($m): int => (int) ($m['player_id'] ?? 0),
                        $members
                    )));
                    if ($seatPids !== []) {
                        $profiles = make(MemberService::class)->getAll(['id' => $seatPids], 'id,points');
                        foreach ($profiles as $profile) {
                            $pts = bcadd((string) ($profile['points'] ?? '0'), '0', 2);
                            if (bccomp($pts, $entryPoints, 2) < 0) {
                                throw new ApiException('有玩家金币不足，无法开始游戏');
                            }
                        }
                    }
                }

                // 正式开局：先发牌并落库，再进入抢庄；每人只收自己的牌
                $seats = array_map(static fn ($m): int => (int) $m['seat_no'], $members);
                $deal = Deal::dealToSeats($seats);
                $players = [];
                foreach ($members as $m) {
                    $seat = (int) $m['seat_no'];
                    $hand = $deal['hands'][$seat] ?? [];
                    sort($hand);
                    $players[] = [
                        'seat_no'    => $seat,
                        'player_id'  => (int) $m['player_id'],
                        'nickname'   => (string) ($m['nickname'] ?? ''),
                        'avatar'     => (string) ($m['avatar'] ?? ''),
                        'avatar_url' => (string) ($m['avatar_url'] ?? ''),
                        'fd'         => (int) ($m['fd'] ?? 0),
                        'cards'      => $hand,
                    ];
                }
                make(GamePlayCardService::class)->recordDeal((int) $round->id, $players);

                $bankerMode = $roomService->resolveNiuBankerMode($room->rule ?? null);
                $banker = null;
                if ($bankerMode !== 'free') {
                    $banker = self::resolveFixedBanker(
                        $bankerMode,
                        (int) ($room->played_count ?? 0),
                        $roomId,
                        $players,
                        $gameRoundService
                    );
                }
                $bankerSeat = (int) ($banker['seat_no'] ?? -1);
                self::beginGameRecord($roomId, (int) $round->id, $players, $bankerSeat, $bankerMode);

                $phase = $banker === null ? NiuConsts::PHASE_GRAB : NiuConsts::PHASE_SHOW;
                $firstGrabber = null;
                // 自由抢庄：全员同时可抢，不指定 current；轮流/其他模式按座位顺序
                if ($phase === NiuConsts::PHASE_GRAB && $bankerMode !== 'free') {
                    $ordered = $players;
                    usort($ordered, static fn (array $a, array $b): int => (int) $a['seat_no'] <=> (int) $b['seat_no']);
                    $firstGrabber = $ordered[0] ?? null;
                }
                $gameRoundService->update($round->id, [
                    'status'    => GameRound::STATUS_PLAYING,
                    'master_id' => (int) ($banker['player_id'] ?? 0),
                    'multiple'  => 1,
                    'extra'     => RoundPhase::encode(
                        $phase,
                        NiuConsts::CARDS_PER_PLAYER,
                        isset($firstGrabber['player_id']) ? (int) $firstGrabber['player_id'] : null,
                        isset($firstGrabber['seat_no']) ? (int) $firstGrabber['seat_no'] : null
                    ),
                ]);
                if ((int) $room->status !== GameRoom::STATUS_PLAYING) {
                    $roomService->update($roomId, ['status' => GameRoom::STATUS_PLAYING]);
                }
                $roomMemberService->markPlayingForRoom($roomId);

                return [
                    'round_id'  => $round->id,
                    'room_num'  => (string) $room->room_num,
                    'ready_ids' => $readyIds,
                    'started'   => true,
                    'need'      => $need,
                    'players'   => $players,
                    'phase'     => $phase,
                    'bankerMode'=> $bankerMode,
                    'bankerSeat'=> $bankerSeat,
                    'bankerPid' => (int) ($banker['player_id'] ?? 0),
                    'currentGrabPid' => (int) ($firstGrabber['player_id'] ?? 0),
                    'currentGrabSeat'=> (int) ($firstGrabber['seat_no'] ?? -1),
                ];
            });
        } catch (ApiException $e) {
            return ['code' => 400, 'message' => $e->getMessage(), 'data' => null];
        } catch (\Throwable $e) {
            Log::error('斗牛开始游戏失败', [
                'room_id'   => $roomId,
                'player_id' => $playerId,
                'error'     => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);

            return ['code' => 500, 'message' => '开始游戏失败，请重试', 'data' => null];
        }

        // ============================================================
        // 事务外：挂超时 + 组推送
        // ============================================================
        // 阶段变化后重置房间倒计时（抢庄 15s）
        make(RoomTimeoutService::class)->armGameTimeout($roomId);

        if (! empty($outcome['already'])) {
            return ['code' => 400, 'message' => '本局已开始', 'data' => null];
        }

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

        // 满员开局：公共座位只有张数。每人私发自己的 myCards，避免互相看见牌面。
        $publicSeats = [];
        foreach ($outcome['players'] as $p) {
            $publicSeats[] = [
                'seat_no'    => (int) $p['seat_no'],
                'seatNo'     => (int) $p['seat_no'],
                'player_id'  => (int) $p['player_id'],
                'playerId'   => (int) $p['player_id'],
                'nickname'   => (string) ($p['nickname'] ?? ''),
                'avatar'     => (string) ($p['avatar'] ?? ''),
                'avatar_url' => (string) ($p['avatar_url'] ?? ''),
                'cardsCount' => count($p['cards'] ?? []),
            ];
        }

        $base = [
            'callback'       => CallBack::START_GAME,
            'roomId'         => $outcome['room_num'],
            'roundId'        => $outcome['round_id'],
            'phase'          => $outcome['phase'] ?? NiuConsts::PHASE_GRAB,
            'grabMultiples'  => NiuConsts::GRAB_MULTIPLES,
            'bankerMode'     => $outcome['bankerMode'] ?? 'free',
            'bankerIndex'    => $outcome['bankerSeat'] ?? -1,
            'bankerPlayerId' => $outcome['bankerPid'] ?? 0,
            'currentPlayerId'=> $outcome['currentGrabPid'] ?? 0,
            'currentIndex'   => $outcome['currentGrabSeat'] ?? -1,
            'players'        => $publicSeats,
        ];

        $perFd = [];
        $selfData = null;
        foreach ($outcome['players'] as $p) {
            $pfd = (int) ($p['fd'] ?? 0);
            $pid = (int) $p['player_id'];
            $payload = $base;
            $payload['myCards'] = array_values(is_array($p['cards'] ?? null) ? $p['cards'] : []);
            if ($pfd > 0) {
                $perFd[$pfd] = ['code' => 0, 'message' => 'ok', 'data' => $payload];
            }
            if ($pid === $playerId) {
                $selfData = $payload;
            }
        }

        return [
            'code'       => 0,
            'message'    => 'ok',
            'data'       => $selfData ?? $base,
            '_broadcast' => ['perFd' => $perFd],
        ];
    }

    /**
     * @param list<array{seat_no:int,player_id:int,nickname?:string,avatar?:string,cards?:list<string>}> $players
     */
    private static function beginGameRecord(
        int $roomId,
        int $roundId,
        array $players,
        int $bankerSeat,
        string $bankerMode
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
                    'isBanker' => $bankerSeat >= 0 && $seat === $bankerSeat,
                ];
            }
            GameRecordHooks::beginFromRoom($meta, $roundId, $recordPlayers, [
                'seats'      => $seats,
                'dealerSeat' => $bankerSeat,
                'bankerSeat' => $bankerSeat,
                'ext'        => [
                    'bankerMode' => $bankerMode,
                ],
            ]);
        } catch (\Throwable) {
            // 录像失败不影响牌局
        }
    }

    /**
     * 轮流/赢家模式定庄：首局随机；后续轮流取上局庄家下一座，赢家取上局大赢家。
     *
     * @param list<array{seat_no:int,player_id:int}> $players
     * @return array{seat_no:int,player_id:int}
     */
    private static function resolveFixedBanker(
        string $mode,
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
            'id,master_id,winner_user_ids',
            ['id' => 'desc']
        );
        $last = $previous[0] ?? null;
        if ($last === null) {
            return $players[array_rand($players)];
        }
        $field = static function (array|object $row, string $key): mixed {
            return is_array($row) ? ($row[$key] ?? null) : ($row->{$key} ?? null);
        };

        if ($mode === 'winner') {
            $winnerIds = self::parseIds((string) $field($last, 'winner_user_ids'));
            foreach ($winnerIds as $winnerId) {
                foreach ($players as $player) {
                    if ((int) $player['player_id'] === $winnerId) {
                        return $player;
                    }
                }
            }
            return $players[array_rand($players)];
        }

        $lastMaster = (int) $field($last, 'master_id');
        foreach ($players as $index => $player) {
            if ((int) $player['player_id'] === $lastMaster) {
                return $players[($index + 1) % count($players)];
            }
        }

        return $players[array_rand($players)];
    }

    /** 解析 round.player_ids（逗号分隔） */
    private static function parseIds(?string $ids): array
    {
        $list = array_map('intval', array_filter(explode(',', (string) $ids), static fn ($v) => $v !== ''));

        return array_values(array_unique($list));
    }
}
