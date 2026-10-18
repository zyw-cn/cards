<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Utils\TraceId;
use Hyperf\Context\Context;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class LogIdMiddleware implements MiddlewareInterface
{
    public const LOG_ID_KEY = 'log_id';
    public const HEADER_LOG_ID = 'X-Request-ID';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $logId = TraceId::generate();
        Context::set(self::LOG_ID_KEY, $logId);
        $response = $handler->handle($request);
        return $response->withHeader(self::HEADER_LOG_ID, $logId);
    }
}
