<?php

declare(strict_types=1);

namespace App\Exception\Handler;

use Hyperf\ExceptionHandler\ExceptionHandler;
use Hyperf\RateLimit\Exception\RateLimitException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class RateLimitExceptionHandler extends ExceptionHandler
{
    public function handle(Throwable $throwable, ResponseInterface $response): ResponseInterface
    {
        $this->stopPropagation();

        return app('Json')->fail('发送过于频繁，请稍后再试', [], 429);
    }

    public function isValid(Throwable $throwable): bool
    {
        return $throwable instanceof RateLimitException;
    }
}
