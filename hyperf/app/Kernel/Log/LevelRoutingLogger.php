<?php

declare(strict_types=1);

namespace App\Kernel\Log;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * 根据日志级别自动路由到不同 channel 的 Logger
 */
class LevelRoutingLogger extends AbstractLogger
{
    public function __construct(
        private LoggerInterface $infoLogger,
        private LoggerInterface $warnLogger,
        private LoggerInterface $errorLogger,
    ) {}

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        match ($level) {
            'warning'                          => $this->warnLogger->warning($message, $context),
            'error', 'critical',
            'alert', 'emergency'               => $this->errorLogger->log($level, $message, $context),
            default                            => $this->infoLogger->log($level, $message, $context),
        };
    }
}
