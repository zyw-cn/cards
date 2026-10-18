<?php

declare(strict_types=1);

namespace App\Game\Platform\Action;

use App\Game\Contract\GameActionInterface;
use App\Game\Contract\GameContext;
use App\Game\Platform\RoomCallBack;
use App\Service\Game\RoomMemberService;
use App\Utils\WebSocketSession;
use function Hyperf\Support\make;

/**
 * 房间快捷聊天（路由 Room.Chat）
 *
 * body: { text: string } 或 { content: string }
 * 广播给同房其他人；本人回包也带同一份 data，便于本地立刻展示。
 */
final class ChatAction implements GameActionInterface
{
    /** 与前端 quickMessages 对齐的上限，防灌水 */
    private const MAX_LEN = 40;

    public function handle(GameContext $ctx): array
    {
        $body = $ctx->body;
        $playerId = WebSocketSession::uid($body);
        $fd = (int) ($body['fd'] ?? 0);

        if ($playerId <= 0) {
            return ['code' => 401, 'message' => '请先登录', 'data' => null];
        }

        $text = trim((string) ($body['text'] ?? $body['content'] ?? $body['message'] ?? ''));
        if ($text === '') {
            return ['code' => 400, 'message' => '消息不能为空', 'data' => null];
        }
        if (mb_strlen($text) > self::MAX_LEN) {
            $text = mb_substr($text, 0, self::MAX_LEN);
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

        $members = $roomMemberService->getActiveInRoom($roomId, 'fd,player_id,seat_no');
        $targetFds = $roomMemberService->otherFds($members, $fd);

        $data = [
            'callback' => RoomCallBack::CHAT,
            'text'     => $text,
            'content'  => $text,
            'seatNo'   => $seatNo,
            'playerId' => $playerId,
        ];

        return [
            'code'       => 0,
            'message'    => 'ok',
            'data'       => $data,
            '_broadcast' => [
                'targetFds' => $targetFds,
                'payload'   => ['code' => 0, 'message' => 'ok', 'data' => $data],
            ],
        ];
    }
}
