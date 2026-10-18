<?php

declare(strict_types=1);

use function Hyperf\Support\env;

$appUrl = rtrim((string) env('APP_URL', ''), '/');

return [
    'avatar' => [
        // 本地存储根目录（其下为 avatar/Y/m/d/xxx.ext）
        'disk' => BASE_PATH . '/storage/uploads',
        // 对外访问前缀，如 https://api.example.com/uploads/avatar
        'url' => $appUrl !== '' ? $appUrl . '/uploads/avatar' : '/uploads/avatar',
        'max_size' => (int) env('AVATAR_MAX_SIZE', 2 * 1024 * 1024),
        'allowed_mimes' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
        'allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
    ],
    'recharge' => [
        'disk' => BASE_PATH . '/storage/uploads',
        'url' => $appUrl !== '' ? $appUrl . '/uploads/recharge' : '/uploads/recharge',
        'max_size' => (int) env('RECHARGE_SCREENSHOT_MAX_SIZE', 5 * 1024 * 1024),
        'allowed_mimes' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
        'allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
    ],
];
