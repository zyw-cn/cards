<?php

declare(strict_types=1);

namespace App\Kernel\Log;

use Hyperf\Logger\LoggerFactory as HyperfLoggerFactory;
use Psr\Log\LoggerInterface;

class LoggerFactory extends HyperfLoggerFactory
{
    protected array $routingLoggers = [];

    /**
     * 这些频道直接写入各自的日志文件，不走级别路由.
     */
    protected array $directChannels = ['curl', 'sql', 'business'];

    public function get($name = 'app', $group = 'default'): LoggerInterface
    {
        if ($group !== 'default') {
            return parent::get($name, $group);
        }

        if (in_array($name, $this->directChannels, true)) {
            return parent::get($name, $name);
        }

        return $this->routingLoggers[$name] ??= new LevelRoutingLogger(
            parent::get($name, 'info'),
            parent::get($name, 'warn'),
            parent::get($name, 'error'),
        );
    }
}
