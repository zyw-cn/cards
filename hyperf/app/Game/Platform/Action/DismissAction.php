<?php

declare(strict_types=1);

namespace App\Game\Platform\Action;

use App\Game\Contract\GameActionInterface;
use App\Game\Contract\GameContext;
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
 * 房主手动解散房间（路由 Room.Dismiss）
 *
 * 仅「从未开局」时房主可直接解散；房间已开局后须全员投票同意。
 */
final class DismissAction implements GameActionInterface
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
            return ['code' => 400, 'message' => '你不在房间内', 'data' => null];
        }

        $roomId = (int) $member->room_id;
        $room = make(RoomService::class)->getOne(
            ['id' => $roomId],
            'id,room_num,status,num,game_type,member_id,played_count'
        );
        if ($room === null || ! GameRoom::isActiveStatus((int) ($room->status ?? 0))) {
            return ['code' => 400, 'message' => '房间不存在', 'data' => null];
        }

        $ownerId = (int) ($room->member_id ?? 0);
        if ($ownerId <= 0 || $ownerId !== $playerId) {
            return ['code' => 403, 'message' => '只有房主可以解散房间', 'data' => null];
        }

        $playing = make(GameRoundService::class)->getOne([
            'room_id' => $roomId,
            'status'  => GameRound::STATUS_PLAYING,
        ], 'id');
        $playedCount = (int) ($room->played_count ?? 0);
        if ($playing !== null || $playedCount > 0) {
            return [
                'code'    => 400,
                'message' => '房间已开局，请申请解散并由全员同意',
                'data'    => null,
            ];
        }

        $roomNum = (string) ($room->room_num ?? '');
        $reason = '房主已解散房间';
        make(RoomTimeoutService::class)->dissolveRoom($roomId, $reason, $fd, [
            'leftBy'   => $playerId,
            'leftName' => '房主',
            'byOwner'  => true,
        ]);

        return [
            'code'    => 0,
            'message' => 'ok',
            'data'    => [
                'callback' => RoomCallBack::ROOM_DISSOLVE,
                'roomId'   => $roomNum,
                'reason'   => $reason,
                'left'     => true,
                'byOwner'  => true,
            ],
        ];
    }
}
