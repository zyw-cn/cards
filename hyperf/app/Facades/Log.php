<?php

declare(strict_types=1);

namespace App\Facades;

use App\Service\Sys\LogService;
use FriendsOfHyperf\Facade\Facade;
use Psr\Log\LoggerInterface;

/**
 * Log 门面 — 静态调用代理到 LogService 实例
 *
 * @method static LoggerInterface channel(string $name = 'default')
 * @method static void info(string $message, array $context = [])
 * @method static void debug(string $message, array $context = [])
 * @method static void warn(string $message, array $context = [])
 * @method static void warning(string $message, array $context = [])
 * @method static void error(string $message, array $context = [])
 * @method static void curl(string $message, array $context = [])
 * @method static void sql(string $message, array $context = [])
 * @method static void business(string $message, array $context = [])
 *
 * @see LogService
 */
class Log extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return LogService::class;
    }
}
