<?php

declare(strict_types=1);

namespace App\Exception\Handler;

use App\Utils\TraceId;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\ExceptionHandler\ExceptionHandler;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class AppExceptionHandler extends ExceptionHandler
{
    public function __construct(protected StdoutLoggerInterface $logger)
    {
    }

    public function handle(Throwable $throwable, ResponseInterface $response)
    {
        $this->logger->error(sprintf('%s[%s] in %s', $throwable->getMessage(), $throwable->getLine(), $throwable->getFile()));
        $this->logger->error($throwable->getTraceAsString());

        $this->stopPropagation();

        $body = json_encode([
            'code'      => 500,
            'msg'       => 'Internal Server Error',
            'log_id'    => TraceId::getId(),
            'timestamp' => time(),
        ], JSON_UNESCAPED_UNICODE);

        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Server', 'Hyperf')
            ->withStatus(500)
            ->withBody(new SwooleStream($body));
    }

    public function isValid(Throwable $throwable): bool
    {
        return true;
    }
}
