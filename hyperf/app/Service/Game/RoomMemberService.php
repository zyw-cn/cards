<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Dao\Game\RoomMemberDao;
use App\Model\Game\RoomMember;
use App\Service\BaseService;
use App\Utils\WebSocketSession;
use Hyperf\Database\Model\Model;

/**
 * 房间成员 + 出牌阶段托管状态（is_auto / time_out_num / status 存在 h_room_member）
 *
 * status：0 未开始，1 游戏中，2 已结束（解散不删行，重进复用并改 fd/status）
 */
class RoomMemberService extends BaseService
{
    /** 连续出牌超时达此次数 → 自动进入托管 */
    public const AUTO_HOST_THRESHOLD = 3;

    public function __construct(RoomMemberDao $roomMemberDao)
    {
        $this->dao = $roomMemberDao;
    }

    /** 进行中成员条件：status < 2 */
    private function activeWhere(array $extra = []): array
    {
        return array_merge($extra, [['status', '<', RoomMember::STATUS_ENDED]]);
    }

    /**
     * 玩家当前有效成员行（未结束）
     */
    public function getActiveByPlayer(int $playerId): ?Model
    {
        if ($playerId <= 0) {
            return null;
        }
        return $this->getOne($this->activeWhere(['player_id' => $playerId]));
    }

    /**
     * 房间内有效成员列表
     *
     * @return list<array<string, mixed>>
     */
    public function getActiveInRoom(int $roomId, string $field = '*', array $orderBy = ['seat_no' => 'asc']): array
    {
        if ($roomId <= 0) {
            return [];
        }
        return $this->getAll($this->activeWhere(['room_id' => $roomId]), $field, $orderBy);
    }

    /**
     * 取房间内所有在线连接的 fd 列表（用于广播，仅有效成员）
     *
     * @return list<int>
     */
    public function getFdsInRoom(int $roomId): array
    {
        if ($roomId <= 0) {
            return [];
        }
        $rows = $this->getActiveInRoom($roomId, 'fd,player_id');
        $fds = [];
        foreach ($rows as $row) {
            $fd = $this->resolveMemberFd($row);
            if ($fd > 0) {
                $fds[] = $fd;
            }
        }
        return array_values(array_unique($fds));
    }

    /**
     * 成员行上的 fd 可能过期；优先库内 fd，否则用当前 WS 会话反查。
     *
     * @param array<string, mixed>|object $row
     */
    public function resolveMemberFd(array|object $row): int
    {
        $playerId = (int) (is_array($row) ? ($row['player_id'] ?? 0) : ($row->player_id ?? 0));
        // 优先用当前 WS 会话 fd（库内 fd 可能过期，会导致广播丢人）
        if ($playerId > 0) {
            $live = WebSocketSession::getFdByUid($playerId);
            if ($live > 0) {
                return $live;
            }
        }
        $fd = (int) (is_array($row) ? ($row['fd'] ?? 0) : ($row->fd ?? 0));

        return $fd > 0 ? $fd : 0;
    }

    /**
     * 刷新成员当前连接 fd（发消息 / 出牌前调用，避免广播丢包）
     */
    public function touchMemberFd(int $memberRowId, int $fd): void
    {
        if ($memberRowId <= 0 || $fd <= 0) {
            return;
        }
        $this->update($memberRowId, ['fd' => $fd]);
    }

    /**
     * 取房间内除 excludeFd 外的 fd 列表
     *
     * @param array<int, array{fd?: int}> $members
     * @return list<int>
     */
    public function otherFds(array $members, int $excludeFd): array
    {
        $fds = [];
        foreach ($members as $m) {
            $fd = $this->resolveMemberFd($m);
            if ($fd > 0 && $fd !== $excludeFd) {
                $fds[] = $fd;
            }
        }
        return array_values(array_unique($fds));
    }

    /**
     * 出牌结果推给其他人前：去掉手牌与托管私有字段，防透视 / 误切 UI
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function publicPlayPayload(array $data): array
    {
        unset(
            $data['myCards'],
            $data['myTiles'],
            $data['myWaitOps'],
            $data['drawnTile']
        );
        return $data;
    }

    /**
     * 断线：清空该玩家（或该 fd）在有效成员行上的 fd，避免脏连接被继续推送
     */
    public function clearFdOnDisconnect(int $fd, int $uid = 0): void
    {
        if ($uid > 0) {
            $this->update($this->activeWhere(['player_id' => $uid]), ['fd' => 0]);
            return;
        }
        if ($fd > 0) {
            $this->update($this->activeWhere(['fd' => $fd]), ['fd' => 0]);
        }
    }

    /**
     * 房间解散 / 打满局：成员 status=2，不删行；清 fd 与托管
     */
    public function markEndedForRoom(int $roomId): void
    {
        if ($roomId <= 0) {
            return;
        }
        $this->update(
            $this->activeWhere(['room_id' => $roomId]),
            [
                'status'       => RoomMember::STATUS_ENDED,
                'exit_time'    => date('Y-m-d H:i:s'),
                'fd'           => 0,
                'is_auto'      => 0,
                'time_out_num' => 0,
            ]
        );
    }

    /** 单个玩家离开座位（未发牌离开 / 返回大厅） */
    public function markEndedForPlayer(int $roomId, int $playerId): void
    {
        if ($roomId <= 0 || $playerId <= 0) {
            return;
        }
        $this->update(
            $this->activeWhere(['room_id' => $roomId, 'player_id' => $playerId]),
            [
                'status'       => RoomMember::STATUS_ENDED,
                'exit_time'    => date('Y-m-d H:i:s'),
                'fd'           => 0,
                'is_auto'      => 0,
                'time_out_num' => 0,
            ]
        );
    }

    /**
     * 发牌开局：有效成员标为游戏中，并重置托管计数
     */
    public function markPlayingForRoom(int $roomId): void
    {
        if ($roomId <= 0) {
            return;
        }
        $this->update(
            $this->activeWhere(['room_id' => $roomId]),
            [
                'status'       => RoomMember::STATUS_PLAYING,
                'is_auto'      => 0,
                'time_out_num' => 0,
            ]
        );
    }

    /** 某玩家在该房间是否处于托管中（仅有效成员） */
    public function isTrusted(int $roomId, int $playerId): bool
    {
        if ($roomId <= 0 || $playerId <= 0) {
            return false;
        }
        return $this->getOne(
            $this->activeWhere([
                'room_id'   => $roomId,
                'player_id' => $playerId,
                'is_auto'   => 1,
            ]),
            'id'
        ) !== null;
    }

    /** 置某玩家为托管（点「离开」时调用） */
    public function setTrusted(int $roomId, int $playerId): void
    {
        if ($roomId <= 0 || $playerId <= 0) {
            return;
        }
        $this->update(
            $this->activeWhere(['room_id' => $roomId, 'player_id' => $playerId]),
            ['is_auto' => 1]
        );
    }

    /** 取消托管并清零超时计数（玩家手动出牌时调用） */
    public function clearTrust(int $roomId, int $playerId): void
    {
        if ($roomId <= 0 || $playerId <= 0) {
            return;
        }
        $this->update(
            $this->activeWhere(['room_id' => $roomId, 'player_id' => $playerId]),
            ['is_auto' => 0, 'time_out_num' => 0]
        );
    }

    /**
     * 累计一次「出牌超时未手动操作」；未托管且连续达阈值时自动进入托管
     *
     * @return bool 本次是否刚触发自动托管
     */
    public function registerPlayTimeout(int $roomId, int $playerId): bool
    {
        if ($roomId <= 0 || $playerId <= 0 || $this->isTrusted($roomId, $playerId)) {
            return false;
        }

        $member = $this->getOne(
            $this->activeWhere(['room_id' => $roomId, 'player_id' => $playerId]),
            'id,time_out_num'
        );
        if ($member === null) {
            return false;
        }

        $new = (int) ($member->time_out_num ?? 0) + 1;
        $justTrusted = $new >= self::AUTO_HOST_THRESHOLD;

        $this->update(
            ['id' => (int) $member->id],
            [
                'time_out_num' => $new,
                'is_auto'      => $justTrusted ? 1 : 0,
            ]
        );

        return $justTrusted;
    }
}
