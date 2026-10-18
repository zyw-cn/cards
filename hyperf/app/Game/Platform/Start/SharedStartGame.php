<?php

declare(strict_types=1);

namespace App\Game\Platform\Start;

use App\Exception\ApiException;
use App\Game\Platform\Timeout\RoomTimeoutService;
use App\Model\Game\GameRoom;
use App\Model\Game\GameRound;
use App\Service\Game\GameRoundService;
use App\Service\Game\RoomMemberService;
use App\Service\Game\RoomService;
use App\Service\Member\MemberService;
use App\Utils\WebSocketSession;
use function Hyperf\Support\make;

/**
 * 开局公共壳：会话解析、房间锁、局行挑选、等待广播、金币校验等。
 * 发牌 / 叫抢等玩法逻辑仍留在各游戏 StartGameAction。
 */
final class SharedStartGame
{
    /**
     * @param array<string, mixed> $body
     * @return array{playerId:int,roomId:int,fd:int,member:object,roomMemberService:RoomMemberService,roomService:RoomService,gameRoundService:GameRoundService}|array{error: array{code:int,message:string,data:null}}
     */
    public static function resolveSession(array $body, bool $touchFd = true): array
    {
        $playerId = WebSocketSession::uid($body);
        if ($playerId <= 0) {
            return ['error' => ['code' => 401, 'message' => '请先登录', 'data' => null]];
        }

        $roomMemberService = make(RoomMemberService::class);
        $roomService = make(RoomService::class);
        $gameRoundService = make(GameRoundService::class);

        $member = $roomMemberService->getActiveByPlayer($playerId);
        if ($member === null) {
            return ['error' => ['code' => 400, 'message' => '您还未加入任何房间', 'data' => null]];
        }

        $roomId = (int) $member->room_id;
        $fd = (int) ($body['fd'] ?? 0);
        if ($touchFd && $fd > 0) {
            $roomMemberService->touchMemberFd((int) $member->id, $fd);
        }

        return [
            'playerId'           => $playerId,
            'roomId'             => $roomId,
            'fd'                 => $fd,
            'member'             => $member,
            'roomMemberService'  => $roomMemberService,
            'roomService'        => $roomService,
            'gameRoundService'   => $gameRoundService,
        ];
    }

    /**
     * @param list<string> $extraColumns
     */
    public static function lockRoomForStart(RoomService $roomService, int $roomId, array $extraColumns = []): object
    {
        $columns = array_values(array_unique(array_merge(
            ['id', 'room_num', 'status', 'played_count', 'total_play_count', 'rule'],
            $extraColumns
        )));
        $room = $roomService->lockForUpdate(['id' => $roomId], $columns);
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

        return $room;
    }

    /**
     * 补齐昵称 / 头像完整 URL，供前端座位展示
     *
     * @param list<array<string,mixed>> $members
     * @return list<array<string,mixed>>
     */
    public static function enrichMembers(array $members): array
    {
        $memberService = make(MemberService::class);
        $playerIds = array_values(array_unique(array_filter(array_map(
            static fn ($m): int => (int) ($m['player_id'] ?? 0),
            $members
        ))));
        $profiles = [];
        if ($playerIds !== []) {
            foreach ($memberService->getAll(['id' => $playerIds], 'id,avatar,nickname,realname,points') as $profile) {
                $mid = (int) ($profile['id'] ?? 0);
                if ($mid > 0) {
                    $profiles[$mid] = $profile;
                }
            }
        }
        foreach ($members as &$m) {
            $pid = (int) ($m['player_id'] ?? 0);
            $profile = $profiles[$pid] ?? [];
            $rel = (string) ($profile['avatar'] ?? $m['avatar'] ?? '');
            $m['nickname'] = (string) ($profile['nickname'] ?? $profile['realname'] ?? $m['nickname'] ?? ('玩家' . $pid));
            $m['avatar'] = $rel;
            $m['avatar_url'] = $memberService->buildAvatarUrl($rel);
            $m['points'] = (float) ($profile['points'] ?? $m['points'] ?? 0);
        }
        unset($m);

        return $members;
    }

    /**
     * @param list<array<string,mixed>> $members
     */
    public static function assertGoldEnough(RoomService $roomService, ?string $rule, array $members): void
    {
        $entryPoints = $roomService->resolveEntryPoints($rule);
        $bottom = $roomService->resolveBottomPoints($rule);
        if (bccomp($entryPoints, '0', 2) <= 0) {
            return;
        }
        $seatPids = array_values(array_filter(array_map(
            static fn ($m): int => (int) ($m['player_id'] ?? 0),
            $members
        )));
        if ($seatPids === []) {
            return;
        }
        $profiles = make(MemberService::class)->getAll(['id' => $seatPids], 'id,points');
        foreach ($profiles as $profile) {
            $pts = bcadd((string) ($profile['points'] ?? '0'), '0', 2);
            if (bccomp($pts, $entryPoints, 2) < 0) {
                throw new ApiException(sprintf(
                    '有玩家金币不足：金币数量至少是底分的5倍（底分 %s，需至少 %s）',
                    $bottom,
                    $entryPoints
                ));
            }
        }
    }

    /** @return list<int> */
    public static function parseIds(?string $ids): array
    {
        $list = array_map('intval', array_filter(explode(',', (string) $ids), static fn ($v) => $v !== ''));

        return array_values(array_unique($list));
    }

    /** getAll 返回 array，兼容 Model / array 行 */
    public static function roundField(array|object $row, string $key): mixed
    {
        if (is_array($row)) {
            return $row[$key] ?? null;
        }

        return $row->{$key} ?? null;
    }

    public static function roundSetField(array|object &$row, string $key, mixed $value): void
    {
        if (is_array($row)) {
            $row[$key] = $value;

            return;
        }
        $row->{$key} = $value;
    }

    /** @param array<string, mixed>|object $row */
    public static function roundAsObject(array|object $row): object
    {
        return is_object($row) ? $row : (object) $row;
    }

    /**
     * 取当前房间「未结束」的最新一局；已结算但未标 ENDED 的局会被收口。
     *
     * @param callable(array|object $round): bool $isActivelyPlaying
     * @return object|null 含 id/status/player_ids/master_id/winner_user_ids/extra
     */
    public static function pickOpenRoundRow(
        int $roomId,
        GameRoundService $gameRoundService,
        callable $isActivelyPlaying
    ): ?object {
        $rows = $gameRoundService->getAll(
            ['room_id' => $roomId, ['status', '<', GameRound::STATUS_ENDED]],
            'id,status,player_ids,master_id,winner_user_ids,extra',
            ['id' => 'desc']
        );
        $newestPending = null;
        foreach ($rows as $round) {
            $rid = (int) self::roundField($round, 'id');
            if ($rid <= 0) {
                continue;
            }
            $winner = trim((string) self::roundField($round, 'winner_user_ids'));
            if ($winner !== '') {
                $gameRoundService->update($rid, ['status' => GameRound::STATUS_ENDED]);
                continue;
            }
            $status = (int) self::roundField($round, 'status');
            if ($status === GameRound::STATUS_PLAYING) {
                if ($isActivelyPlaying($round)) {
                    return self::roundAsObject($round);
                }
                $gameRoundService->update($rid, ['status' => GameRound::STATUS_ENDED]);
                continue;
            }
            if ($status === GameRound::STATUS_PENDING) {
                if ($newestPending === null) {
                    $newestPending = $round;
                } else {
                    $keepId = (int) self::roundField($newestPending, 'id');
                    $merged = array_values(array_unique(array_merge(
                        self::parseIds((string) self::roundField($newestPending, 'player_ids')),
                        self::parseIds((string) self::roundField($round, 'player_ids'))
                    )));
                    if ($merged !== []) {
                        $gameRoundService->update($keepId, ['player_ids' => implode(',', $merged)]);
                        self::roundSetField($newestPending, 'player_ids', implode(',', $merged));
                    }
                    $gameRoundService->update($rid, ['status' => GameRound::STATUS_ENDED]);
                }
            }
        }

        return $newestPending === null ? null : self::roundAsObject($newestPending);
    }

    /**
     * @param array<string, mixed> $outcome
     * @param array<string, mixed> $extraPayload keys merged into wait payload (askMode, need, …)
     * @return array{code: int, message: string, data: mixed, _broadcast?: array}
     */
    public static function buildWaitBroadcast(
        array $outcome,
        int $roomId,
        RoomMemberService $roomMemberService,
        string $waitCallback,
        array $extraPayload = []
    ): array {
        $payload = array_merge([
            'callback'   => $waitCallback,
            'roomId'     => $outcome['room_num'],
            'roundId'    => $outcome['round_id'],
            'readyCount' => count($outcome['ready_ids']),
            'readyIds'   => $outcome['ready_ids'],
            'rule'       => (string) ($outcome['rule'] ?? ''),
            'ruleObj'    => $outcome['ruleObj'] ?? [],
        ], $extraPayload);
        $targetFds = $roomMemberService->getFdsInRoom($roomId);

        return [
            'code'       => 0,
            'message'    => 'ok',
            'data'       => $payload,
            '_broadcast' => [
                'targetFds' => array_values(array_unique($targetFds)),
                'payload'   => ['code' => 0, 'message' => 'ok', 'data' => $payload],
            ],
        ];
    }

    public static function armTimeout(int $roomId): void
    {
        make(RoomTimeoutService::class)->armGameTimeout($roomId);
    }
}
