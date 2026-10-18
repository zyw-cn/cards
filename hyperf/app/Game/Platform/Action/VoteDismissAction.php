<?php

declare(strict_types=1);

namespace App\Game\Platform\Action;

use App\Game\Contract\GameActionInterface;
use App\Game\Contract\GameContext;
use App\Game\Platform\DismissVoteService;
use App\Game\Platform\RoomCallBack;
use App\Game\Platform\RoomWsPusher;
use App\Game\Platform\Timeout\RoomTimeoutService;
use App\Model\Game\GameRoom;
use App\Service\Game\RoomMemberService;
use App\Service\Game\RoomService;
use App\Utils\WebSocketSession;
use function Hyperf\Support\make;

/**
 * 解散房间投票（路由 Room.VoteDismiss / Room.VoteClose）
 *
 * body: { vote: 1|2 }  1=同意 2=拒绝
 */
final class VoteDismissAction implements GameActionInterface
{
    public function handle(GameContext $ctx): array
    {
        $body = $ctx->body;
        $playerId = WebSocketSession::uid($body);
        $fd = (int) ($body['fd'] ?? 0);
        if ($playerId <= 0) {
            return ['code' => 401, 'message' => '请先登录', 'data' => null];
        }

        $voteRaw = (int) ($body['vote'] ?? $body['agree'] ?? 0);
        if ($voteRaw !== DismissVoteService::STATE_AGREE && $voteRaw !== DismissVoteService::STATE_REFUSE) {
            return ['code' => 400, 'message' => '请选择同意或拒绝', 'data' => null];
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

        $room = make(RoomService::class)->getOne(['id' => $roomId], 'id,room_num,status');
        if ($room === null || ! GameRoom::isActiveStatus((int) $room->status)) {
            return ['code' => 400, 'message' => '房间不存在或已结束', 'data' => null];
        }
        $roomNum = (string) ($room->room_num ?? '');

        $svc = make(DismissVoteService::class);
        $result = $svc->castVote($roomId, $seatNo, $voteRaw);
        if (! ($result['ok'] ?? false)) {
            return ['code' => 400, 'message' => (string) ($result['message'] ?? '投票失败'), 'data' => null];
        }

        /** @var array<string, mixed> $vote */
        $vote = $result['vote'];
        $outcome = (string) ($result['outcome'] ?? 'continue');
        $members = $roomMemberService->getActiveInRoom($roomId, 'fd,player_id');
        $targetFds = $roomMemberService->otherFds($members, $fd);

        if ($outcome === 'cancel') {
            make(RoomTimeoutService::class)->resumeGameTimeoutAfterDismiss($roomId);
            $payload = array_merge(
                $svc->clientPayload($vote, RoomCallBack::CANCEL_DISMISS),
                [
                    'reason' => '有玩家拒绝解散',
                    'seatNo' => $seatNo,
                    'vote'   => DismissVoteService::STATE_REFUSE,
                ]
            );

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

        if ($outcome === 'dissolve') {
            $svc->clear($roomId);
            $reason = '全员同意解散房间';
            // 先推一次最终投票态，再解散（excludeFd 由 dissolve 跳过本人回包）
            $votePayload = array_merge(
                $svc->clientPayload($vote, RoomCallBack::VOTE_DISMISS),
                [
                    'seatNo'   => $seatNo,
                    'vote'     => DismissVoteService::STATE_AGREE,
                    'dissolve' => true,
                ]
            );
            make(RoomWsPusher::class)->pushDataToMembers(
                $members,
                $votePayload,
                $fd
            );
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

        $payload = array_merge(
            $svc->clientPayload($vote, RoomCallBack::VOTE_DISMISS),
            [
                'seatNo' => $seatNo,
                'vote'   => $voteRaw,
            ]
        );

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
