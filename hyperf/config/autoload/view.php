<?php

declare(strict_types=1);

use Hyperf\View\Mode;
use Hyperf\ViewEngine\HyperfViewEngine;

return [
    'engine' => HyperfViewEngine::class,
    'mode' => Mode::SYNC,
    'config' => [
        'view_path' => BASE_PATH . '/storage/view/',
        'cache_path' => BASE_PATH . '/runtime/view/',
        'charset' => 'UTF-8',
    ],
    'autoload' => [
        'classes' => [
            'App\View\Component\\',
        ],
        'components' => [
            'components.',
        ],
    ],
    'components' => [],
    'namespaces' => [],
];
