<?php

declare(strict_types=1);

namespace App\Utils;

use App\Constants\RedisKey;
use Hyperf\Redis\Redis;
use function Hyperf\Support\make;

class WebSocketSession
{
    public static function bind(int $fd, int $uid): void
    {
        if ($fd <= 0 || $uid <= 0) {
            return;
        }

        $redis = self::redis();
        $redis->set(sprintf(RedisKey::USER_ROOM, $uid), $fd);
        $redis->set(sprintf(RedisKey::USER_FD, $fd), $uid);
    }

    public static function uid(array $body): int
    {
        $uid = (int) ($body['uid'] ?? $body['member_id'] ?? $body['player_id'] ?? 0);
        if ($uid > 0) {
            return $uid;
        }

        return self::getUidByFd((int) ($body['fd'] ?? 0));
    }

    public static function getUidByFd(int $fd): int
    {
        if ($fd <= 0) {
            return 0;
        }

        return (int) self::redis()->get(sprintf(RedisKey::USER_FD, $fd));
    }

    public static function getFdByUid(int $uid): int
    {
        if ($uid <= 0) {
            return 0;
        }

        return (int) self::redis()->get(sprintf(RedisKey::USER_ROOM, $uid));
    }

    public static function forgetFd(int $fd): void
    {
        if ($fd <= 0) {
            return;
        }

        $redis = self::redis();
        $uid = (int) $redis->get(sprintf(RedisKey::USER_FD, $fd));
        if ($uid > 0 && (int) $redis->get(sprintf(RedisKey::USER_ROOM, $uid)) === $fd) {
            $redis->del(sprintf(RedisKey::USER_ROOM, $uid));
        }

        $redis->del(sprintf(RedisKey::USER_FD, $fd));
    }

    private static function redis(): Redis
    {
        return make(Redis::class);
    }

}
