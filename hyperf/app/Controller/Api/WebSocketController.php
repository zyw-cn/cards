<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Game\Dispatcher;
use App\Model\Game\GameRoom;
use App\Model\Game\RoomMember;
use App\Service\Game\RoomMemberService;
use App\Service\Game\RoomService;
use App\Protocol\BinaryPacket;
use App\Utils\WebSocketDebug;
use App\Utils\WebSocketKick;
use App\Utils\WebSocketSession;
use Hyperf\Contract\OnCloseInterface;
use Hyperf\Contract\OnMessageInterface;
use Hyperf\Contract\OnOpenInterface;
use Hyperf\Engine\WebSocket\Frame;
use Hyperf\Engine\WebSocket\Response;
use Hyperf\WebSocketServer\Constant\Opcode;
use Hyperf\WebSocketServer\Sender;
use HyperfExtension\Jwt\Contracts\JwtFactoryInterface;
use HyperfExtension\Jwt\Jwt;
use function Hyperf\Support\make;

/**
 * WebSocket 二进制协议入口（BinaryPacket）
 * - TYPE_MESSAGE：route + body → Dispatcher → GameRegistry/Module Action（如 Dou.Bid）
 * - 支持 _broadcast / perFd 向房间内连接推送
 */
class WebSocketController implements OnMessageInterface, OnOpenInterface, OnCloseInterface
{
    private Jwt $jwt;

    public function __construct(JwtFactoryInterface $jwtFactory)
    {
        $this->jwt = $jwtFactory->make();
    }

    public function onMessage($server, $frame): void
    {
        $response = (new Response($server))->init($frame);
        if ($frame->opcode !== Opcode::BINARY) {
            $this->pushBinary($response, BinaryPacket::TYPE_NOTIFY, [
                'code' => 400,
                'message' => 'Only binary frames are supported',
            ]);
            return;
        }

        $this->handleBinary($server, $frame, $response);
    }

    private function handleBinary($server, $frame, Response $response): void
    {
        $fd = (int) ($frame->fd ?? 0);
        $uid = WebSocketSession::getUidByFd($fd);
        if (!$uid) {
            $this->pushBinary($response, BinaryPacket::TYPE_MESSAGE, [
                'code' => 401,
                'message' => '请先登录',
                'data' => null,
            ]);
            return;
        }

        try {
            $parsed = BinaryPacket::decode($frame->data ?? '');
        } catch (\Throwable $e) {
            $this->pushBinary($response, BinaryPacket::TYPE_NOTIFY, [
                'code' => 400,
                'message' => 'Invalid binary packet: ' . $e->getMessage(),
            ]);
            return;
        }

        $body = (string) ($parsed['body'] ?? '');
        match ($parsed['type']) {
            BinaryPacket::TYPE_PING    => $this->handlePing($response),
            BinaryPacket::TYPE_CHAT    => $this->handleChat($server, $body),
            BinaryPacket::TYPE_MESSAGE => $this->handleMessage($server, $frame, $response, $body),
            BinaryPacket::TYPE_NOTIFY  => $this->handleNotify($server, $body),
            default => $this->pushBinary($response, BinaryPacket::TYPE_NOTIFY, [
                'code' => 400,
                'message' => 'Unknown packet type: ' . $parsed['type'],
            ]),
        };
    }

    private function handlePing(Response $response): void
    {
        $this->pushBinary($response, BinaryPacket::TYPE_PING, [
            'pong' => true,
            'time' => time(),
        ]);
    }

    private function handleChat($server, string $body): void
    {
        $data = BinaryPacket::decodeBodyJson($body);
        $roomId = (string) ($data['roomId'] ?? '');
        if ($roomId === '') {
            return;
        }

        $encoded = BinaryPacket::encodeJson(BinaryPacket::TYPE_CHAT, [
            'roomId' => $roomId,
            'from' => $data['from'] ?? null,
            'content' => (string) ($data['content'] ?? ''),
            'time' => time(),
        ]);

        $this->pushEncodedToFds($this->fdsInRoom((string) $roomId), 0, $encoded);
    }

    /**
     * TYPE_MESSAGE：body JSON { route, body|args, requestId? } → Dispatcher → 回包 + 可选 _broadcast
     * requestId 仅回写给请求方，不带入房间广播，避免推送被前端误当成 RPC 回包。
     */
    private function handleMessage($server, $frame, Response $response, string $body): void
    {
        $fd = (int) ($frame->fd ?? 0);
        $uid = WebSocketSession::getUidByFd($fd);
        $startedAt = microtime(true);
        $route = '';
        $requestId = null;

        try {
            $decoded = BinaryPacket::decodeBodyJson($body);
            $route = (string) ($decoded['route'] ?? '');
            $requestId = $decoded['requestId'] ?? null;
            if ($requestId !== null && $requestId !== '') {
                $requestId = is_scalar($requestId) ? (string) $requestId : null;
            } else {
                $requestId = null;
            }
            $messageBody = $decoded['body'] ?? $decoded['args'] ?? [];
            if (! is_array($messageBody)) {
                $messageBody = [];
            }
            $messageBody['fd'] = $fd;
            if ($uid > 0) {
                $messageBody['uid'] = $uid;
            }

            WebSocketDebug::log('recv', [
                'fd'        => $fd,
                'uid'       => $uid,
                'route'     => $route,
                'requestId' => $requestId,
            ]);

            $result = (new Dispatcher())->dispatch([
                'route' => $route,
                'body'  => $messageBody,
            ]);

            $broadcast = $result['_broadcast'] ?? null;
            if ($broadcast !== null) {
                unset($result['_broadcast']);
            }

            if ($requestId !== null) {
                $result['requestId'] = $requestId;
            }

            $this->pushBinary($response, BinaryPacket::TYPE_MESSAGE, $result);

            if ($broadcast !== null && (isset($broadcast['payload']) || ! empty($broadcast['perFd']))) {
                $this->broadcastMessage($server, $frame, $broadcast);
            }

            WebSocketDebug::log('reply', [
                'fd'        => $fd,
                'uid'       => $uid,
                'route'     => $route,
                'requestId' => $requestId,
                'code'      => (int) ($result['code'] ?? 0),
                'message'   => (string) ($result['message'] ?? ''),
                'ms'        => (int) round((microtime(true) - $startedAt) * 1000),
                'broadcast' => $broadcast !== null ? [
                    'targetFds' => count($broadcast['targetFds'] ?? []),
                    'perFd'     => count($broadcast['perFd'] ?? []),
                ] : null,
            ]);
        } catch (\JsonException) {
            $err = [
                'code'    => 400,
                'message' => 'Invalid JSON',
                'data'    => null,
            ];
            if ($requestId !== null) {
                $err['requestId'] = $requestId;
            }
            $this->pushBinary($response, BinaryPacket::TYPE_MESSAGE, $err);
            WebSocketDebug::log('error', ['fd' => $fd, 'uid' => $uid, 'route' => $route, 'error' => 'Invalid JSON']);
        } catch (\Throwable $e) {
            $err = [
                'code'    => 500,
                'message' => $e->getMessage(),
                'data'    => null,
            ];
            if ($requestId !== null) {
                $err['requestId'] = $requestId;
            }
            $this->pushBinary($response, BinaryPacket::TYPE_MESSAGE, $err);
            WebSocketDebug::log('error', [
                'fd'    => $fd,
                'uid'   => $uid,
                'route' => $route,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function broadcastMessage($server, $frame, array $broadcast): void
    {
        $excludeFd = (int) ($frame->fd ?? 0);

        // 按 fd 定制 payload（发牌时每人只收自己的手牌）
        if (! empty($broadcast['perFd']) && is_array($broadcast['perFd'])) {
            WebSocketDebug::log('broadcast', [
                'excludeFd' => $excludeFd,
                'perFd'     => count($broadcast['perFd']),
            ]);
            foreach ($broadcast['perFd'] as $fd => $payload) {
                $fd = (int) $fd;
                if ($fd <= 0 || $fd === $excludeFd) {
                    continue;
                }
                $this->safeSenderPush(
                    $fd,
                    BinaryPacket::encodeJson(BinaryPacket::TYPE_MESSAGE, $payload)
                );
            }
            return;
        }

        $fds = $broadcast['targetFds'] ?? $this->fdsInRoom((string) ($broadcast['roomId'] ?? ''));
        $encoded = BinaryPacket::encodeJson(BinaryPacket::TYPE_MESSAGE, $broadcast['payload']);
        WebSocketDebug::log('broadcast', [
            'excludeFd' => $excludeFd,
            'targetFds' => is_array($fds) ? count($fds) : 0,
            'roomId'    => (string) ($broadcast['roomId'] ?? ''),
        ]);
        $this->pushEncodedToFds($fds, $excludeFd, $encoded);
    }

    private function handleNotify($server, string $body): void
    {
        $data = BinaryPacket::decodeBodyJson($body);
        $encoded = BinaryPacket::encodeJson(BinaryPacket::TYPE_NOTIFY, [
            'title' => (string) ($data['title'] ?? ''),
            'content' => (string) ($data['content'] ?? ''),
            'time' => time(),
        ]);

        $target = (string) ($data['target'] ?? 'room');
        $fds = match ($target) {
            'fd' => [(int) ($data['fd'] ?? 0)],
            'all' => iterator_to_array($server->connections),
            default => $this->fdsInRoom((string) ($data['roomId'] ?? '')),
        };

        $this->pushEncodedToFds($fds, 0, $encoded);
    }

    public function onClose($server, int $fd, int $reactorId): void
    {
        $uid = WebSocketSession::getUidByFd($fd);
        WebSocketDebug::log('close', ['fd' => $fd, 'uid' => $uid]);
        WebSocketSession::forgetFd($fd);
        // 同步清掉成员表 fd，避免断线后仍按脏 fd 推送 / 超时代打
        try {
            make(RoomMemberService::class)->clearFdOnDisconnect($fd, (int) $uid);
        } catch (\Throwable $e) {
            WebSocketDebug::log('close_fd_clear_fail', [
                'fd'    => $fd,
                'uid'   => $uid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function onOpen($server, $request): void
    {
        $response = (new Response($server))->init($request);
        $fd = (int) $request->fd;

        $token = $this->resolveToken($request);
        if ($token === '') {
            $this->rejectOpen($server, $response, $fd, 'Missing token');
            return;
        }

        try {
            $this->jwt->setToken($token);
            $payload = $this->jwt->getPayload();
        } catch (\Throwable) {
            $this->rejectOpen($server, $response, $fd, 'Invalid token');
            return;
        }

        if ($payload->get('token_type') !== 'access') {
            $this->rejectOpen($server, $response, $fd, 'Invalid token type');
            return;
        }

        $uid = (int) $payload->get('sub');
        if ($uid <= 0) {
            $this->rejectOpen($server, $response, $fd, 'Invalid token');
            return;
        }

        $lastFd = WebSocketSession::getFdByUid($uid);
        // 仅踢掉「其他仍存活的连接」；$lastFd === $fd 时 fd 被复用，不能踢自己
        if ($lastFd > 0 && $lastFd !== $fd) {
            WebSocketKick::disconnectUid($uid, true, $fd);
        } elseif ($lastFd > 0 && $lastFd === $fd) {
            WebSocketSession::forgetFd($lastFd);
        }

        // 鉴权/查库期间客户端可能已断开（快速重连），勿再 push 已失效 fd
        if (! $this->fdAlive($server, $fd)) {
            WebSocketDebug::log('open_aborted', ['fd' => $fd, 'uid' => $uid]);
            return;
        }

        WebSocketSession::bind($fd, $uid);

        $loginData = ['uid' => $uid];
        // 断线重连：若该用户仍在某个进行中的房间，通知前端重新进入
        $reconnect = $this->resolveReconnectRoom($uid);
        if ($reconnect !== null) {
            $loginData['reconnect'] = $reconnect;
        }

        // 查库后又可能已断开
        if (! $this->fdAlive($server, $fd)) {
            WebSocketSession::forgetFd($fd);
            WebSocketDebug::log('open_aborted_after_bind', ['fd' => $fd, 'uid' => $uid]);
            return;
        }

        WebSocketDebug::log('open', [
            'fd'        => $fd,
            'uid'       => $uid,
            'reconnect' => $reconnect !== null,
        ]);

        $this->pushBinary($response, BinaryPacket::TYPE_NOTIFY, [
            'code'    => 0,
            'message' => '登录成功',
            'data'    => $loginData,
        ]);
    }

    /**
     * 查询用户当前仍有效的房间（用于断线重连回到房间）
     * @param int $uid 用户ID
     * @return array{roomId: string, room_num: string, gameType: int, status: int}|null 无有效房间返回 null
     */
    private function resolveReconnectRoom(int $uid): ?array
    {
        $roomIds = RoomMember::query()
            ->where('player_id', $uid)
            ->where('status', '<', RoomMember::STATUS_ENDED)
            ->pluck('room_id')
            ->all();

        if (empty($roomIds)) {
            return null;
        }

        $room = GameRoom::query()
            ->whereIn('id', $roomIds)
            ->whereIn('status', GameRoom::activeStatuses())
            ->orderByDesc('id')
            ->first(['id', 'room_num', 'game_type', 'status']);

        if ($room === null) {
            return null;
        }

        return [
            'roomId' => (string) $room->room_num,
            'room_num' => (string) $room->room_num,
            'gameType' => (int) $room->game_type,
            'status' => (int) $room->status,
        ];
    }

    /**
     * 根据房间号解析房间内所有 fd（聊天/广播用）
     *
     * @return list<int>
     */
    private function fdsInRoom(string $roomNum): array
    {
        if ($roomNum === '') {
            return [];
        }
        $room = make(RoomService::class)->getByRoomNum($roomNum);
        if ($room === null) {
            return [];
        }
        return make(RoomMemberService::class)->getFdsInRoom((int) $room->id);
    }

    private function rejectOpen($server, Response $response, int $fd, string $message): void
    {
        if ($this->fdAlive($server, $fd)) {
            $this->pushBinary($response, BinaryPacket::TYPE_NOTIFY, [
                'code'    => 401,
                'message' => $message,
            ]);
        }
        try {
            if ($server->exist($fd)) {
                $server->disconnect($fd);
            }
        } catch (\Throwable) {
        }
    }

    private function resolveToken($request): string
    {
        $query = $request->get ?? [];
        $token = trim((string) ($query['token'] ?? ''));
        if ($token !== '') {
            return $token;
        }

        $authorization = trim((string) (($request->header ?? [])['authorization'] ?? ''));
        if (stripos($authorization, 'Bearer ') === 0) {
            return trim(substr($authorization, 7));
        }

        return $authorization;
    }

    /** 连接是否仍可推送（快速重连时旧 fd 会失效） */
    private function fdAlive($server, int $fd): bool
    {
        if ($fd <= 0 || $server === null) {
            return false;
        }
        try {
            return $server->exist($fd) && $server->isEstablished($fd);
        } catch (\Throwable) {
            return false;
        }
    }

    /** 安全推送：跨 Worker 用 Sender（本地 $server->push 推不到其它进程的 fd） */
    private function safeSenderPush(int $fd, string $encoded): bool
    {
        if ($fd <= 0) {
            return false;
        }
        try {
            /** @var Sender $sender */
            $sender = make(Sender::class);

            return (bool) $sender->push($fd, $encoded, Opcode::BINARY);
        } catch (\Throwable $e) {
            WebSocketDebug::log('push_skip', [
                'fd'    => $fd,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function pushBinary(Response $response, int $type, array $data): void
    {
        try {
            $response->push(new Frame(
                opcode: Opcode::BINARY,
                payloadData: BinaryPacket::encodeJson($type, $data),
            ));
        } catch (\Throwable $e) {
            // 热更新后旧连接还在，但 worker 里 session 已丢，push 会失败。
            // 不关掉的话客户端一直等回包，最后提示「服务器无响应」。
            $fd = $response->getFd();
            WebSocketDebug::log('push_skip', [
                'fd'    => $fd,
                'error' => $e->getMessage(),
            ]);
            try {
                $response->close();
            } catch (\Throwable) {
            }
        }
    }

    /**
     * @param iterable<int> $fds
     */
    private function pushEncodedToFds(iterable $fds, int $excludeFd, string $encoded): void
    {
        foreach ($fds as $fd) {
            $fd = (int) $fd;
            if ($fd <= 0 || $fd === $excludeFd) {
                continue;
            }
            $this->safeSenderPush($fd, $encoded);
        }
    }
}
