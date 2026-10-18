<?php

declare(strict_types=1);

namespace App\Protocol;

/**
 * 二进制 WebSocket 包体协议（大端序 big-endian）
 *
 * 包结构（共 4 字节头 + N 字节包体）：
 * +--------+--------------------+-----------------------+
 * | type   | length (3 bytes)   | body (length bytes)   |
 * | 1 字节 | uint24 包体字节长度 | UTF-8 字符串(通常 JSON) |
 * +--------+--------------------+-----------------------+
 *
 * type 取值：
 * - 0x01 PING   心跳
 * - 0x02 CHAT   聊天（房间内广播）
 * - 0x03 MESSAGE 业务消息（body JSON: { route, body }，由 Dispatcher 转发）
 * - 0x04 NOTIFY 发送通知（系统通知）
 */
class BinaryPacket
{
    public const TYPE_PING = 0x01;
    public const TYPE_CHAT = 0x02;
    public const TYPE_MESSAGE = 0x03;
    public const TYPE_NOTIFY = 0x04;

    /** 包头固定长度：1 字节 type + 3 字节 length */
    public const HEADER_SIZE = 4;

    /** 包体最大长度：3 字节可表示 0 ~ 16MB-1 */
    public const MAX_BODY_SIZE = 0xFFFFFF;

    /**
     * 将 type 与原始字符串包体编码为二进制帧
     * @param int $type 包类型（见 TYPE_* 常量）
     * @param string $body 原始包体（UTF-8 字符串）
     * @return string 完整二进制帧
     */
    public static function encode(int $type, string $body = ''): string
    {
        $length = strlen($body);
        if ($length > self::MAX_BODY_SIZE) {
            throw new \InvalidArgumentException('Body too large, max ' . self::MAX_BODY_SIZE . ' bytes');
        }
        // 1 字节 type + 3 字节大端长度（取 uint32 大端的后 3 字节）
        $header = pack('C', $type & 0xFF) . substr(pack('N', $length), 1);
        return $header . $body;
    }

    /**
     * 将 type 与数组包体（JSON）编码为二进制帧
     * @param int $type 包类型
     * @param array $data 包体数据，将编码为 JSON
     * @return string 完整二进制帧
     */
    public static function encodeJson(int $type, array $data): string
    {
        return self::encode($type, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * 解码二进制帧为 type / length / body
     * @param string $data 收到的原始二进制数据
     * @return array{type: int, length: int, body: string}
     */
    public static function decode(string $data): array
    {
        if (strlen($data) < self::HEADER_SIZE) {
            throw new \InvalidArgumentException('Packet too short, need at least ' . self::HEADER_SIZE . ' bytes');
        }
        $type = ord($data[0]);
        $length = (ord($data[1]) << 16) | (ord($data[2]) << 8) | ord($data[3]);
        $body = substr($data, self::HEADER_SIZE, $length);
        return [
            'type' => $type,
            'length' => $length,
            'body' => $body,
        ];
    }

    /**
     * 将包体 JSON 字符串解析为数组（空包体返回空数组）
     * @param string $body 包体原始字符串
     * @return array
     */
    public static function decodeBodyJson(string $body): array
    {
        if ($body === '') {
            return [];
        }
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    }
}
