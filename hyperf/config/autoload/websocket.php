<?php

declare(strict_types=1);

use function Hyperf\Support\env;

/**
 * WebSocket 调试开关
 *
 * - 环境变量 WS_DEBUG=1：记录 open/close、收发 route、耗时、广播目标、出牌校验拒绝
 * - 日志写入 debug 通道（见 config/autoload/logger.php）
 * - 生产环境务必关闭，避免日志膨胀与敏感信息泄露
 */
return [
    'debug' => (bool) env('WS_DEBUG', false),
];
