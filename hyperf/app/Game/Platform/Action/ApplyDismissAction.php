<?php

declare(strict_types=1);

namespace App\Game\Platform\Action;

use App\Game\Contract\GameActionInterface;
use App\Game\Contract\GameContext;
use App\Game\Platform\DismissVoteService;
use App\Game\Platform\RoomCallBack;
use App\Game\Platform\Timeout\RoomTimeoutService;
use App\Model\Game\GameRoom;
use App\Model\Game\GameRound;
use App\Service\Game\GameRoundService;
use App\Service\Game\RoomMemberService;
use App\Service\Game\RoomService;
use App\Utils\WebSocketSession;
use function Hyperf\Support\make;

/**
 * 申请解散房间（路由 Room.ApplyDismiss / Room.ApplyClose）
 *
 * 房间已开局后任意玩家可申请；须在座全员明确同意才解散（超时不算同意）。
 * 从未开局请由房主解散。
 */
final class ApplyDismissAction implements GameActionInterface
{
    public function handle(GameContext $ctx): array
    {
        $body = $ctx->body;
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
        if ($fd > 0) {
            $roomMemberService->touchMemberFd((int) $member->id, $fd);
        }

        $room = make(RoomService::class)->getOne(
            ['id' => $roomId],
            'id,room_num,status,num,game_type,played_count'
        );
        if ($room === null || ! GameRoom::isActiveStatus((int) $room->status)) {
            return ['code' => 400, 'message' => '房间不存在或已结束', 'data' => null];
        }

        $playing = make(GameRoundService::class)->getOne([
            'room_id' => $roomId,
            'status'  => GameRound::STATUS_PLAYING,
        ], 'id');
        $playedCount = (int) ($room->played_count ?? 0);
        $roomPlaying = (int) ($room->status ?? 0) === GameRoom::STATUS_PLAYING;
        if ($playing === null && $playedCount <= 0 && ! $roomPlaying) {
            return ['code' => 400, 'message' => '游戏未开始，请由房主解散房间', 'data' => null];
        }

        $members = $roomMemberService->getActiveInRoom($roomId, 'fd,player_id,seat_no');
        $occupiedSeats = [];
        foreach ($members as $m) {
            $seat = (int) ($m['seat_no'] ?? -1);
            if ($seat >= 0) {
                $occupiedSeats[$seat] = $seat;
            }
        }
        $occupiedSeats = array_values($occupiedSeats);
        if ($occupiedSeats === []) {
            return ['code' => 400, 'message' => '房间内没有玩家', 'data' => null];
        }

        $roomNum = (string) ($room->room_num ?? '');
        $playerNum = max(
            (int) ($room->num ?? 0),
            max($occupiedSeats) + 1,
            count($occupiedSeats)
        );
        $playerNum = max(2, min(10, $playerNum));

        $svc = make(DismissVoteService::class);
        $started = $svc->start($roomId, $roomNum, $seatNo, $playerId, $playerNum, [
            'timeoutDissolves' => false,
            'requiredSeats'    => $occupiedSeats,
        ]);
        if (! ($started['ok'] ?? false)) {
            return ['code' => 400, 'message' => (string) ($started['message'] ?? '申请失败'), 'data' => null];
        }

        /** @var array<string, mixed> $vote */
        $vote = $started['vote'];
        $outcome = (string) ($started['outcome'] ?? 'continue');

        if ($outcome === 'dissolve') {
            $svc->clear($roomId);
            $reason = '全员同意解散房间';
            make(RoomTimeoutService::class)->dissolveRoom($roomId, $reason, $fd, [
                'byDismissVote' => true,
            ]);

            return [
                'code'    => 0,
                'message' => 'ok',
                'data'    => [
                    'callback' => RoomCallBack::ROOM_DISSOLVE,
                    'roomId'   => $roomNum,
                    'reason'   => $reason,
                    'left'     => true,
                ],
            ];
        }

        if ($outcome === 'cancel') {
            make(RoomTimeoutService::class)->resumeGameTimeoutAfterDismiss($roomId);
            $payload = array_merge(
                $svc->clientPayload($vote, RoomCallBack::CANCEL_DISMISS),
                ['reason' => '有玩家拒绝解散']
            );
            $targetFds = $roomMemberService->otherFds($members, $fd);

            return [
                'code'       => 0,
                'message'    => 'ok',
                'data'       => $payload,
                '_broadcast' => [
                    'targetFds' => $targetFds,
                    'payload'   => ['code' => 0, 'message' => 'ok', 'data' => $payload],
                ],
            ];
        }

        make(RoomTimeoutService::class)->clearRoomTimeout($roomId);

        $callback = ! empty($started['resumed'])
            ? RoomCallBack::VOTE_DISMISS
            : RoomCallBack::APPLY_DISMISS;
        $payload = $svc->clientPayload($vote, $callback);
        if (! empty($started['resumed'])) {
            $payload['seatNo'] = $seatNo;
            $payload['vote'] = DismissVoteService::STATE_AGREE;
        }
        $targetFds = $roomMemberService->otherFds($members, $fd);

        return [
            'code'       => 0,
            'message'    => 'ok',
            'data'       => $payload,
            '_broadcast' => [
                'targetFds' => $targetFds,
                'payload'   => ['code' => 0, 'message' => 'ok', 'data' => $payload],
            ],
        ];
    }
}