<?php

declare(strict_types=1);

namespace App\Game\Platform\Action;

use App\Constants\GameType;
use App\Exception\ApiException;
use App\Facades\Log;
use App\Game\Contract\GameActionInterface;
use App\Game\Contract\GameContext;
use App\Game\Platform\RoomCallBack;
use App\Game\Room;
use App\Game\Platform\Timeout\RoomTimeoutService;
use App\Game\Support\GameRegistry;
use App\Game\Niu\Action\StartGameAction as NiuStartGameAction;
use App\Model\Game\GameRound;
use App\Service\Game\GameRoundService;
use App\Service\Game\RoomMemberService;
use App\Service\Game\RoomService;
use App\Utils\WebSocketSession;
use function Hyperf\Support\make;

/**
 * 平台加入房间（路由 Room.Join）
 *
 * - DB 入座 → {@see Room::join()}
 * - maxPlayers / resume 按房间 game_type 从对应 GameModule 解析
 */
final class JoinAction implements GameActionInterface
{
    public function handle(GameContext $ctx): array
    {
        $body = $ctx->body;
        $roomNum = trim((string) ($body['room_num'] ?? $body['roomId'] ?? ''));
        $fd = (int) ($body['fd'] ?? 0);
        $playerId = WebSocketSession::uid($body);

        if ($roomNum === '') {
            return ['code' => 400, 'message' => '房间号不能为空', 'data' => null];
        }
        if ($playerId <= 0) {
            return ['code' => 401, 'message' => '请先登录', 'data' => null];
        }

        $roomInfo = make(RoomService::class)->getByRoomNum($roomNum);
        if ($roomInfo === null) {
            return ['code' => 400, 'message' => '不存在对应的房间号', 'data' => null];
        }

        $gameType = (int) ($roomInfo->game_type ?? 0);
        $module = make(GameRegistry::class)->getByType($gameType);
        $maxPlayers = $module?->maxPlayers() ?? 3;
        $roomLimit = (int) ($roomInfo->num ?? 0);
        if ($roomLimit > 0) {
            $maxPlayers = min($maxPlayers, $roomLimit);
        }
        if ($maxPlayers < 2) {
            $maxPlayers = 2;
        }

        try {
            $joinInfo = make(Room::class)->join($roomNum, $playerId, $fd, $maxPlayers);
        } catch (ApiException $e) {
            return ['code' => 400, 'message' => $e->getMessage(), 'data' => null];
        }

        [$targetFds, $publicMembers] = $this->splitPublicMembers($joinInfo['members'] ?? [], $fd);

        $isFull = (bool) ($joinInfo['is_full'] ?? false);
        // 房间规则由服务端下发，前端无需经路由传参
        $ruleRaw = (string) ($roomInfo->rule ?? '');
        $ruleObj = json_decode($ruleRaw, true);
        if (! is_array($ruleObj)) {
            $ruleObj = [];
        }
        $data = [
            'roomId'         => $roomNum,
            'room_num'       => $roomNum,
            'playerId'       => $playerId,
            'seatNo'         => $joinInfo['seat_no'],
            'joined'         => $joinInfo['is_new'],
            'member'         => $joinInfo['member'],
            'playerCount'    => $joinInfo['player_count'],
            'isFull'         => $isFull,
            'canStart'       => $isFull,
            'players'        => $publicMembers,
            'gameType'       => $gameType,
            'ownerId'        => (int) ($roomInfo->member_id ?? 0),
            'totalPlayCount' => (int) ($joinInfo['total_play_count'] ?? $roomInfo->total_play_count ?? 0),
            'playedCount'    => (int) ($roomInfo->played_count ?? 0),
            'num'            => (int) ($roomInfo->num ?? 0),
            'rule'           => $ruleRaw,
            'ruleObj'        => $ruleObj,
            'callback'       => $isFull ? RoomCallBack::ROOM_READY : RoomCallBack::JOIN_ROOM,
        ];

        $ownerId = (int) ($data['ownerId'] ?? 0);
        if ($ownerId > 0) {
            foreach ($data['players'] as &$row) {
                $row['isOwner'] = ((int) ($row['player_id'] ?? 0) === $ownerId);
            }
            unset($row);
        }

        $selfData = $data;
        // 只给本人：广播里的 isMaster 是进房者的，别人拿去会把自己的房主标记冲掉
        $selfData['isMaster'] = $playerId > 0 && $playerId === (int) ($roomInfo->member_id ?? 0);
        $roomId = (int) ($roomInfo->id ?? 0);
        if ($roomId <= 0) {
            $roomId = (int) (make(RoomMemberService::class)->getActiveByPlayer($playerId)?->room_id ?? 0);
        }
        // 本人进房：若有进行中牌局，附带 resume 快照（手牌/阶段/轮次），用于重连恢复
        $selfData['inGame'] = false;
        if ($roomId > 0 && $module !== null) {
            try {
                $resume = $module->resumeBuilder()->build($roomId, $playerId);
                if ($resume !== null) {
                    $selfData['resume'] = $resume;
                    $selfData['inGame'] = true;
                    $selfData['phase'] = $resume['phase'] ?? null;
                    $selfData['canStart'] = false;
                }
            } catch (\Throwable $e) {
                Log::error('进房 resume 快照失败', [
                    'room_id'   => $roomId,
                    'player_id' => $playerId,
                    'error'     => $e->getMessage(),
                    'file'      => $e->getFile() . ':' . $e->getLine(),
                ]);
                $selfData['resumeError'] = $e->getMessage();
            }
        }
        if (empty($selfData['inGame'])) {
            self::attachPendingReady($roomId, $selfData);
            if (! empty($selfData['readyIds'])) {
                $data['readyIds'] = $selfData['readyIds'];
                $data['readyCount'] = $selfData['readyCount'];
            }
        } elseif ($roomId > 0) {
            // 重连进房：Swoole Timer 不跨进程，需重新挂回合超时，否则不会代打
            make(RoomTimeoutService::class)->armGameTimeout($roomId);
        }

        // 斗牛按创建时自定义人数开局：最后一人入座后自动发牌并进入叫分。
        if ($isFull && $gameType === GameType::NIU && empty($selfData['inGame'])) {
            $startPacket = make(NiuStartGameAction::class)->run([
                ...$body,
                '_auto_full' => true,
            ]);
            if ((int) ($startPacket['code'] ?? -1) === 0 && is_array($startPacket['data'] ?? null)) {
                $startPacket['data'] = array_merge($selfData, $startPacket['data']);
                return $startPacket;
            }
        }

        return [
            'code'       => 0,
            'message'    => 'ok',
            'data'       => $selfData,
            '_broadcast' => [
                'targetFds' => $targetFds,
                'payload'   => ['code' => 0, 'message' => 'ok', 'data' => $data],
            ],
        ];
    }

    /**
     * @param array<int, array{fd?: int}> $members
     * @return array{0: list<int>, 1: list<array<string, mixed>>}
     */
    private function splitPublicMembers(array $members, int $selfFd): array
    {
        $roomMemberService = make(RoomMemberService::class);
        $targetFds = [];
        $publicMembers = [];
        foreach ($members as $m) {
            $mfd = $roomMemberService->resolveMemberFd($m);
            if ($mfd > 0 && $mfd !== $selfFd) {
                $targetFds[] = $mfd;
            }
            unset($m['fd']);
            $publicMembers[] = $m;
        }

        return [array_values(array_unique($targetFds)), $publicMembers];
    }

    /**
     * 等待开局阶段：合并本房 PENDING 局上的已准备玩家 id，供进房/刷新座位。
     * 只保留「当前仍在房内」的 id，避免上一局残留把未点开始的人标成已准备。
     *
     * @param array<string, mixed> $data
     */
    private static function attachPendingReady(int $roomId, array &$data): void
    {
        if ($roomId <= 0) {
            return;
        }
        $rows = make(GameRoundService::class)->getAll(
            ['room_id' => $roomId, 'status' => GameRound::STATUS_PENDING],
            'player_ids',
            ['id' => 'desc']
        );
        if ($rows === []) {
            return;
        }
        $ids = [];
        foreach ($rows as $row) {
            $raw = is_array($row) ? (string) ($row['player_ids'] ?? '') : (string) ($row->player_ids ?? '');
            foreach (array_filter(explode(',', $raw), static fn ($v) => $v !== '') as $part) {
                $pid = (int) $part;
                if ($pid > 0) {
                    $ids[] = $pid;
                }
            }
        }
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return;
        }

        $active = make(RoomMemberService::class)->getActiveInRoom($roomId, 'player_id');
        $activeSet = [];
        foreach ($active as $m) {
            $pid = (int) ($m['player_id'] ?? 0);
            if ($pid > 0) {
                $activeSet[$pid] = true;
            }
        }
        $ids = array_values(array_filter($ids, static fn (int $pid): bool => isset($activeSet[$pid])));
        if ($ids === []) {
            return;
        }
        $data['readyIds'] = $ids;
        $data['readyCount'] = count($ids);
    }
}
