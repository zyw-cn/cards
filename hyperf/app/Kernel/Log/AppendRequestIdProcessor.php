<?php

declare(strict_types=1);

namespace App\Kernel\Log;

use App\Utils\TraceId;
use Hyperf\Context\Context;
use Hyperf\Coroutine\Coroutine;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

class AppendRequestIdProcessor implements ProcessorInterface
{
    public function __invoke(array|LogRecord $record): LogRecord
    {
        $append = [
            'log_id'       => TraceId::getId(),
            'coroutine_id' => Coroutine::id(),
        ];

        if ($request = Context::get(\Psr\Http\Message\ServerRequestInterface::class)) {
            $append['uri']    = $request->getUri()->getPath();
            $append['method'] = $request->getMethod();
            $append['ip']     = $request->getServerParams()['remote_addr'] ?? '';
        }

        return $record->with(context: array_merge($record->context, $append));
    }
}
