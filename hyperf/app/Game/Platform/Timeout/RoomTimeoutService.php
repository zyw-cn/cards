<?php

declare(strict_types=1);

namespace App\Game\Platform\Timeout;

use App\Constants\RedisKey;
use App\Dao\Game\GameRoomDao;
use App\Dao\Game\RoomMemberDao;
use App\Facades\Log;
use App\Game\Contract\GameTimeoutHandlerInterface;
use App\Game\Contract\TimeoutPhase;
use App\Game\Platform\DismissVoteService;
use App\Game\Platform\RoomCallBack;
use App\Game\Support\GameRegistry;
use App\Game\Support\GameTimeoutHandlerFactory;
use App\Model\Game\GameRoom;
use App\Service\BaseService;
use App\Service\Game\GameRecordService;
use App\Service\Game\RoomMemberService;
use App\Service\Member\RoomCardService;
use App\Game\Platform\RoomWsPusher;
use Hyperf\DbConnection\Db;
use Hyperf\Redis\Redis;
use Swoole\Timer;
use function Hyperf\Support\make;

/**
 * 房间回合超时内核（Swoole Timer + Redis 令牌）——平台通用，与具体玩法无关
 *
 * - 挂表 / 清表：本类（未开局不自动解散，走申请解散 / 房主解散）
 * - 局内阶段与代打：room.game_type → GameRegistry → 各游戏 TimeoutHandler
 */
class RoomTimeoutService extends BaseService
{
    private const DEFAULT_MAX_PLAYERS = 3;
    private const TIMEOUT_TOKEN_TTL_PAD = 15;

    /** @var array<int, int> room_id => timerId（仅本 worker） */
    private static array $timers = [];

    public function __construct(
        GameRoomDao $gameRoomDao,
        protected RoomMemberDao $roomMemberDao,
        protected RoomMemberService $roomMemberService,
        protected GameTimeoutHandlerFactory $handlerFactory,
        protected RoomWsPusher $pusher,
    ) {
        $this->dao = $gameRoomDao;
    }

    /**
     * 挂载/重置倒计时。任意有效操作后调用即可“续命”。
     * $requiredPlayers 传 null 时按房间 game_type 对应 Module::maxPlayers()。
     *
     * 解散投票进行中不挂回合超时，避免投票期间托管出牌。
     */
    public function armGameTimeout(int $roomId, ?int $requiredPlayers = null): void
    {
        if ($roomId <= 0) {
            return;
        }

        try {
            if (make(DismissVoteService::class)->get($roomId) !== null) {
                $this->clearRoomTimeout($roomId);

                return;
            }

            $requiredPlayers ??= $this->maxPlayersForRoom($roomId);
            $phase = $this->resolvePhase($roomId, $requiredPlayers);
            if ($phase === null) {
                $this->clearRoomTimeout($roomId);
                return;
            }

            $this->clearRoomTimeout($roomId);

            $token = bin2hex(random_bytes(8));
            $this->storeTimeoutToken($roomId, $token, $phase->name, $phase->expectPlayerId, $phase->seconds);
            $this->scheduleTimer($roomId, $requiredPlayers, $token, $phase->seconds);
        } catch (\Throwable $e) {
            Log::error('挂载房间倒计时失败', ['room_id' => $roomId, 'error' => $e->getMessage()]);
        }
    }

    /** 解散投票结束后恢复回合超时 */
    public function resumeGameTimeoutAfterDismiss(int $roomId): void
    {
        if ($roomId <= 0) {
            return;
        }
        $this->armGameTimeout($roomId);
    }

    public function clearRoomTimeout(int $roomId): void
    {
        $timerId = self::$timers[$roomId] ?? null;
        if ($timerId !== null) {
            Timer::clear($timerId);
            unset(self::$timers[$roomId]);
        }
        $this->forgetTimeoutToken($roomId);
    }

    public function handleTimeout(int $roomId, ?int $requiredPlayers = null, ?string $token = null): void
    {
        $requiredPlayers ??= $this->maxPlayersForRoom($roomId);

        if ($token !== null && ! $this->consumeTimeoutToken($roomId, $token, $requiredPlayers)) {
            return;
        }

        // 投票进行中：不自动出牌/推进（保险，防旧 Timer）
        if (make(DismissVoteService::class)->get($roomId) !== null) {
            return;
        }

        $phase = $this->resolvePhase($roomId, $requiredPlayers);
        if ($phase === null || $phase->isWait()) {
            // 等待开局不再超时解散（已有申请解散 / 房主解散）
            return;
        }

        $ok = $this->handlerForRoom($roomId)->onTimeout($roomId, $phase->name, $requiredPlayers);
        if (! $ok) {
            $this->armGameTimeout($roomId, $requiredPlayers);
        }
    }

    public function tryImmediateAutoPlay(int $roomId, int $playerId): void
    {
        if ($roomId <= 0 || $playerId <= 0) {
            return;
        }
        if (make(DismissVoteService::class)->get($roomId) !== null) {
            return;
        }

        $this->handlerForRoom($roomId)->tryImmediateAutoPlay($roomId, $playerId);
    }

    private function resolvePhase(int $roomId, int $requiredPlayers): ?TimeoutPhase
    {
        $room = $this->dao->getOne(['id' => $roomId], 'id,status,game_type');
        if ($room === null || ! GameRoom::isActiveStatus((int) $room->status)) {
            return null;
        }

        $handler = $this->handlerFactory->forGameType((int) $room->game_type);
        // 仅挂局内回合超时；未开局 / 局间等待不自动解散
        return $handler->resolveInGamePhase($roomId, $requiredPlayers);
    }

    private function handlerForRoom(int $roomId): GameTimeoutHandlerInterface
    {
        $room = $this->dao->getOne(['id' => $roomId], 'id,game_type');
        $gameType = (int) ($room->game_type ?? 0);

        return $this->handlerFactory->forGameType($gameType);
    }

    private function maxPlayersForRoom(int $roomId): int
    {
        $room = $this->dao->getOne(['id' => $roomId], 'id,game_type,num');
        if ($room === null) {
            return self::DEFAULT_MAX_PLAYERS;
        }
        $roomLimit = (int) ($room->num ?? 0);
        if ($roomLimit > 0) {
            return $roomLimit;
        }
        $module = make(GameRegistry::class)->getByType((int) $room->game_type);

        return $module?->maxPlayers() ?? self::DEFAULT_MAX_PLAYERS;
    }

    /**
     * 平台统一解散房间（超时 / 长时间无人叫地主等）
     *
     * @param int $excludeFd 不推送给该连接（如主动离开者，已由 Action 回包通知）
     * @param array<string, mixed> $extra 附加推送字段（如 leftName）
     */
    public function dissolveRoom(int $roomId, string $reason, int $excludeFd = 0, array $extra = []): void
    {
        if ($roomId <= 0) {
            return;
        }

        $room = $this->dao->getOne(['id' => $roomId], 'id,room_num,member_id,game_type,played_count,rule');
        if ($room === null) {
            return;
        }

        $members = $this->roomMemberService->getActiveInRoom($roomId, 'player_id,fd');
        $this->closeRoom((array) $room->toArray(), $members, $reason, $excludeFd, $extra);
    }

    /**
     * @param array{id:int, room_num:string, member_id:int, played_count?:int, rule?:string|null} $room
     * @param array<int, array{player_id:int, fd:int}> $members
     * @param array<string, mixed> $extra
     */
    private function closeRoom(array $room, array $members, string $reason, int $excludeFd = 0, array $extra = []): void
    {
        $roomId = (int) $room['id'];
        $now = date('Y-m-d H:i:s');
        $playedCount = (int) ($room['played_count'] ?? 0);
        $ownerId = (int) ($room['member_id'] ?? 0);
        $ruleJson = (string) ($room['rule'] ?? '');

        $won = Db::transaction(function () use ($roomId, $now, $playedCount, $ownerId, $ruleJson) {
            $roomRow = $this->dao->lockForUpdate(
                ['id' => $roomId],
                ['id', 'status', 'played_count', 'total_play_count', 'member_id', 'rule']
            );
            if ($roomRow === null || ! GameRoom::isActiveStatus((int) $roomRow->status)) {
                return false;
            }

            $this->dao->update(
                ['id' => $roomId],
                ['status' => GameRoom::STATUS_ENDED, 'end_time' => $now]
            );

            $this->roomMemberService->markEndedForRoom($roomId);

            $rule = $ruleJson !== '' ? $ruleJson : (string) ($roomRow->rule ?? '');
            $decoded = $rule !== '' ? json_decode($rule, true) : [];
            $typeId = is_array($decoded)
                ? (int) ($decoded['room_card_type_id'] ?? $decoded['card_type_id'] ?? 0)
                : 0;
            $mid = (int) ($roomRow->member_id ?? $ownerId);
            $pc = (int) ($roomRow->played_count ?? $playedCount);
            $total = (int) ($roomRow->total_play_count ?? 0);
            if ($total <= 0 && is_array($decoded)) {
                $total = (int) ($decoded['total_play_count'] ?? $decoded['zjs'] ?? 0);
            }

            if ($mid > 0 && $typeId > 0) {
                try {
                    $cardSvc = make(RoomCardService::class);
                    if ($pc <= 0) {
                        // 未开过一局：整张房卡返还
                        $cardSvc->refundCreateIfNeeded($mid, $roomId, $typeId);
                    } else {
                        // 已开局但未打满：剩余局数折算金币退房主
                        $cardSvc->refundUnusedRoundsAsGoldIfNeeded($mid, $roomId, $typeId, $pc, $total);
                    }
                } catch (\Throwable $e) {
                    Log::error('解散房间退款失败', [
                        'room_id'      => $roomId,
                        'member_id'    => $mid,
                        'type_id'      => $typeId,
                        'played_count' => $pc,
                        'total'        => $total,
                        'error'        => $e->getMessage(),
                    ]);
                }
            }

            return true;
        });

        if ($won) {
            $this->clearRoomTimeout($roomId);
            make(DismissVoteService::class)->clear($roomId);
            make(GameRecordService::class)->abortByRoom($roomId);
            $payload = array_merge($extra, [
                'callback' => RoomCallBack::ROOM_DISSOLVE,
                'roomId'   => (string) ($room['room_num'] ?? ''),
                'reason'   => $reason,
            ]);
            $this->pusher->pushDataToMembers($members, $payload, $excludeFd);
        }
    }

    private function scheduleTimer(int $roomId, int $requiredPlayers, string $token, int $seconds): void
    {
        $delayMs = max(0, (int) $seconds) * 1000;
        $run = function () use ($roomId, $requiredPlayers, $token) {
            unset(self::$timers[$roomId]);
            try {
                $this->handleTimeout($roomId, $requiredPlayers, $token);
            } catch (\Throwable $e) {
                Log::error('房间回合超时处理失败', [
                    'room_id' => $roomId,
                    'error'   => $e->getMessage(),
                ]);
            }
        };
        // seconds=0（托管立即代打）：下一 tick 执行，避免 Timer::after(0) 兼容问题
        $timerId = $delayMs <= 0 ? Timer::after(1, $run) : Timer::after($delayMs, $run);
        self::$timers[$roomId] = $timerId;
    }

    private function timeoutRedisKey(int $roomId): string
    {
        return sprintf(RedisKey::ROOM_TIMEOUT, $roomId);
    }

    private function storeTimeoutToken(int $roomId, string $token, string $phase, int $playerId, int $seconds): void
    {
        try {
            make(Redis::class)->setex(
                $this->timeoutRedisKey($roomId),
                max(1, $seconds + self::TIMEOUT_TOKEN_TTL_PAD),
                json_encode([
                    'token'     => $token,
                    'phase'     => $phase,
                    'player_id' => $playerId,
                ], JSON_UNESCAPED_UNICODE)
            );
        } catch (\Throwable $e) {
            Log::error('写入房间超时令牌失败', ['room_id' => $roomId, 'error' => $e->getMessage()]);
        }
    }

    private function forgetTimeoutToken(int $roomId): void
    {
        try {
            make(Redis::class)->del($this->timeoutRedisKey($roomId));
        } catch (\Throwable) {
        }
    }

    private function consumeTimeoutToken(int $roomId, string $token, int $requiredPlayers): bool
    {
        try {
            $redis = make(Redis::class);
            $key = $this->timeoutRedisKey($roomId);
            $raw = $redis->get($key);
            if ($raw === false || $raw === null || $raw === '') {
                return false;
            }

            $meta = json_decode((string) $raw, true);
            if (! is_array($meta) || (string) ($meta['token'] ?? '') !== $token) {
                return false;
            }

            if (! $this->tokenStillMatchesTurn($roomId, $meta, $requiredPlayers)) {
                return false;
            }

            $redis->del($key);

            return true;
        } catch (\Throwable $e) {
            Log::error('校验房间超时令牌失败', ['room_id' => $roomId, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * @param array{phase?:string, player_id?:int} $meta
     */
    private function tokenStillMatchesTurn(int $roomId, array $meta, int $requiredPlayers): bool
    {
        $expectPhase = (string) ($meta['phase'] ?? '');
        $expectPid = (int) ($meta['player_id'] ?? 0);

        // 历史 wait 令牌一律作废：等待阶段已不再超时解散
        if ($expectPhase === TimeoutPhase::WAIT || $expectPhase === '') {
            return false;
        }

        return $this->handlerForRoom($roomId)->actorStillMatches($roomId, $expectPhase, $expectPid);
    }
}
