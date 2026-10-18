<?php


declare(strict_types=1);

namespace App\Helper;

use Hyperf\Context\Context;
use Hyperf\Snowflake\IdGeneratorInterface;
use Hyperf\Context\ApplicationContext;
class TraceId
{
    public static function generate(): string
    {

        $container = ApplicationContext::getContainer();

        // 如果有雪花算法生成器，使用雪花ID
        if ($container->has(IdGeneratorInterface::class)) {
            /** @var IdGeneratorInterface $generator */
            $generator = $container->get(IdGeneratorInterface::class);
            $id = $generator->generate();
            return (string) $id;
        }

        // 否则使用微秒时间戳
        return sprintf(
            'trace_%s_%s_%d',
            uniqid(),
            (int)(microtime(true) * 1000),
            rand(1000, 9999)
        );
    }

    public static function getId(): string
    {
        if (!Context::has('log_id')) {
            $traceId = self::generate();
            Context::set('log_id', $traceId);
            Context::set('trace_id', $traceId);
        }

        return Context::get('log_id', 'no-trace-id');
    }

    public static function setId(string $traceId): void
    {
        Context::set('log_id', $traceId);
        Context::set('trace_id', $traceId);
    }
}