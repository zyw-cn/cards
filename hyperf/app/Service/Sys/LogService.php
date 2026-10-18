<?php

declare(strict_types=1);

namespace App\Service\Sys;

use Hyperf\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;

class LogService
{
    public function __construct(protected LoggerFactory $loggerFactory)
    {
    }

    public function channel(string $name = 'default'): LoggerInterface
    {
        return $this->loggerFactory->get($name, $name);
    }

    public function info(string $message, array $context = []): void
    {
        $this->channel('info')->info($message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->channel('debug')->debug($message, $context);
    }

    public function warn(string $message, array $context = []): void
    {
        $this->channel('warn')->warning($message, $context);
    }

    /** PSR-3 别名，兼容 Log::warning() */
    public function warning(string $message, array $context = []): void
    {
        $this->warn($message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->channel('error')->error($message, $context);
    }

    public function curl(string $message, array $context = []): void
    {
        $this->channel('curl')->info($message, $context);
    }

    public function sql(string $message, array $context = []): void
    {
        $this->channel('sql')->info($message, $context);
    }

    public function business(string $message, array $context = []): void
    {
        $this->channel('business')->info($message, $context);
    }
}
