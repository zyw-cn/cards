<?php

declare(strict_types=1);

namespace App\Utils;

use App\Protocol\BinaryPacket;
use App\Service\Game\RoomMemberService;
use Hyperf\WebSocketServer\Constant\Opcode;
use Hyperf\WebSocketServer\Sender;
use function Hyperf\Support\make;

/**
 * 主动断开用户 WebSocket（登出、踢下线等）
 */
class WebSocketKick
{
    public static function disconnectUid(int $uid, bool $notifyLogout = true, int $exceptFd = 0): void
    {
        if ($uid <= 0) {
            return;
        }

        $fd = WebSocketSession::getFdByUid($uid);
        if ($fd <= 0 || ($exceptFd > 0 && $fd === $exceptFd)) {
            return;
        }

        /** @var Sender $sender */
        $sender = make(Sender::class);

        if ($notifyLogout) {
            try {
                $sender->push(
                    $fd,
                    BinaryPacket::encodeJson(BinaryPacket::TYPE_NOTIFY, [
                        'code'    => 401,
                        'message' => 'logout',
                    ]),
                    Opcode::BINARY
                );
            } catch (\Throwable) {
            }
        }

        try {
            $sender->disconnect($fd);
        } catch (\Throwable) {
        }

        WebSocketSession::forgetFd($fd);
        try {
            make(RoomMemberService::class)->clearFdOnDisconnect($fd, $uid);
        } catch (\Throwable) {
        }
    }
}
