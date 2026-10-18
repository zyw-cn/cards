<?php

declare(strict_types=1);

namespace App\Utils;

use App\Facades\Log;

/**
 * WebSocket 调试日志（受 config websocket.debug / 环境变量 WS_DEBUG 控制）
 */
class WebSocketDebug
{
    public static function enabled(): bool
    {
        return (bool) \Hyperf\Config\config('websocket.debug', false);
    }

    public static function log(string $event, array $context = []): void
    {
        if (! self::enabled()) {
            return;
        }
        Log::debug('[WS] ' . $event, $context);
    }

    /** 记录业务拒绝（出牌/叫抢校验失败等） */
    public static function logReject(string $event, array $context = []): void
    {
        self::log($event, $context);
    }
}
