<?php

declare(strict_types=1);

namespace App\Game\Platform;

use App\Protocol\BinaryPacket;
use App\Service\Game\RoomMemberService;
use Hyperf\WebSocketServer\Constant\Opcode;
use Hyperf\WebSocketServer\Sender;
use function Hyperf\Support\make;

/**
 * 平台 WebSocket 推送（二进制包、按 fd 广播）
 *
 * 归属 Game/Platform：与 JoinAction、RoomTimeoutService 同级，不是表访问层。
 * Action 也可直接返回 _broadcast，由 WS 入口统一 push；本类供超时/关房等无 Action 回包场景。
 */
class RoomWsPusher
{
    public function __construct(
        protected RoomMemberService $roomMemberService,
    ) {
    }

    public function pushPayloadToFds(array $perFd): void
    {
        /** @var Sender $sender */
        $sender = make(Sender::class);
        foreach ($perFd as $fd => $payload) {
            $fd = (int) $fd;
            if ($fd <= 0 || ! is_array($payload)) {
                continue;
            }
            try {
                $sender->push($fd, BinaryPacket::encodeJson(BinaryPacket::TYPE_MESSAGE, $payload), Opcode::BINARY);
            } catch (\Throwable) {
            }
        }
    }

    /**
     * 本人保留 myCards/autoHosted；其他人走脱敏 publicPlayPayload。
     *
     * @param array<int, array{fd?: int, player_id?: int}> $members
     * @param array<string, mixed> $data
     */
    public function pushPlayResultToMembers(array $members, array $data, int $ownerPlayerId): void
    {
        /** @var Sender $sender */
        $sender = make(Sender::class);
        foreach ($members as $m) {
            $fd = (int) ($m['fd'] ?? 0);
            if ($fd <= 0) {
                continue;
            }
            $payload = ((int) ($m['player_id'] ?? 0) === $ownerPlayerId)
                ? $data
                : $this->roomMemberService->publicPlayPayload($data);
            try {
                $sender->push(
                    $fd,
                    BinaryPacket::encodeJson(BinaryPacket::TYPE_MESSAGE, [
                        'code'    => 0,
                        'message' => 'ok',
                        'data'    => $payload,
                    ]),
                    Opcode::BINARY
                );
            } catch (\Throwable) {
            }
        }
    }

    /**
     * @param array<int, array{fd?: int, player_id?: int}> $members
     */
    public function pushDataToMembers(array $members, array $data, int $excludeFd = 0): void
    {
        $encoded = BinaryPacket::encodeJson(BinaryPacket::TYPE_MESSAGE, [
            'code'    => 0,
            'message' => 'ok',
            'data'    => $data,
        ]);

        /** @var Sender $sender */
        $sender = make(Sender::class);
        $seen = [];
        $pushed = 0;
        foreach ($members as $m) {
            $fd = $this->roomMemberService->resolveMemberFd($m);
            if ($fd <= 0 || $fd === $excludeFd || isset($seen[$fd])) {
                continue;
            }
            $seen[$fd] = true;
            try {
                $ok = $sender->push($fd, $encoded, Opcode::BINARY);
                if ($ok) {
                    ++$pushed;
                }
            } catch (\Throwable) {
            }
        }
        if ($pushed === 0 && $members !== []) {
            // 叫分等进度推送若目标为 0，便于排查 Redis fd / 成员表
            \App\Facades\Log::warning('RoomWsPusher pushDataToMembers empty', [
                'excludeFd' => $excludeFd,
                'members'   => count($members),
                'callback'  => (string) ($data['callback'] ?? ''),
                'resolved'  => array_keys($seen),
            ]);
        }
    }
}
