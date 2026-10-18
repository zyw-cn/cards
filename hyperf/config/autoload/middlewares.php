<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
return [
    'http' => [
        \Gokure\HyperfCors\CorsMiddleware::class,
        \App\Middleware\LogIdMiddleware::class,
        \App\Middleware\RequestLifecycleMiddleware::class,
        \Hyperf\Validation\Middleware\ValidationMiddleware::class,
    ],
];
