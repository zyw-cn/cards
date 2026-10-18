<?php

declare(strict_types=1);

use App\Kernel\Log\AppendRequestIdProcessor;
use App\Kernel\Log\HourlyRotatingFileHandler;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;

use function Hyperf\Support\env;

$appEnv = env('APP_ENV', 'local');
$isDev  = $appEnv === 'local';

$format    = "[%datetime%] %level_name%: %message% %context%\n";
$formatter = [
    'class'       => LineFormatter::class,
    'constructor' => [
        'format'                => $format,
        'dateFormat'            => 'Y-m-d H:i:s',
        'allowInlineLineBreaks' => true,
    ],
];

$processors = [
    ['class' => AppendRequestIdProcessor::class],
];

$channels = [
    'info'     => Level::Debug,
    'curl'     => Level::Debug,
    'warn'     => Level::Debug,
    'error'    => Level::Debug,
    'debug'    => Level::Debug,
    'sql'      => Level::Debug,
    'business' => Level::Debug,
];

$config = [];

foreach ($channels as $name => $level) {
    if ($isDev) {
        $config[$name] = [
            'handlers' => [
                [
                    'class'       => StreamHandler::class,
                    'constructor' => [
                        'stream' => 'php://stdout',
                        'level'  => $level,
                    ],
                    'formatter' => $formatter,
                ],
            ],
            'processors' => $processors,
        ];
    } else {
        $config[$name] = [
            'handlers' => [
                [
                    'class'       => HourlyRotatingFileHandler::class,
                    'constructor' => [
                        'filename' => env("LOG_PATH")."/{$name}.log",
                        'maxFiles' => (int)env("LOG_MAX_FILES"),
                        'level'    => $level,
                    ],
                    'formatter' => $formatter,
                ],
            ],
            'processors' => $processors,
        ];
    }
}

$config['default'] = $config['info'];

return $config;
