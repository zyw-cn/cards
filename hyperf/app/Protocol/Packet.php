<?php

declare(strict_types=1);

namespace App\Protocol;

/**
 * 消息包：message 为 JSON，包含 route 与 body
 * route 格式：Game.Logic.Action，如 Dou.Room.Create
 */
class Packet
{
    /**
     * 将数组编码为 JSON 字符串（WebSocket 消息体）
     * @param array $data 含 code、message、data 等
     * @return string JSON 字符串
     */
    public function encode(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * 将 JSON 字符串解码为 route 与 body（支持 body 或 args 键）
     * @param string $data 客户端发来的 JSON 字符串
     * @return array{route: string, body: array}
     * @throws \JsonException
     */
    public function decode(string $data): array
    {
        $decoded = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('Invalid message: not array');
        }
        $route = $decoded['route'] ?? '';
        $body = $decoded['body'] ?? $decoded['args'] ?? [];
        return [
            'route' => (string) $route,
            'body' => is_array($body) ? $body : [],
        ];
    }
}