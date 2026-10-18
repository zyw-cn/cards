<?php

declare(strict_types=1);

use Hyperf\RateLimit\Storage\RedisStorage;

return [
    // 每秒生成令牌数
    'create' => 1,
    // 每次请求消耗令牌数
    'consume' => 1,
    // 桶容量（与 create 配合控制突发）
    'capacity' => 1,
    'limitCallback' => [],
    // 不等待，超限直接走 limitCallback / 抛 RateLimitException
    'waitTimeout' => 0,
    'storage' => [
        'class' => RedisStorage::class,
        'options' => [
            'pool' => 'default',
            'expired_time' => 0,
        ],
    ],
];
