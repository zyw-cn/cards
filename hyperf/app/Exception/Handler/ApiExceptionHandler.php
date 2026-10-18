<?php

declare(strict_types=1);

namespace App\Exception\Handler;

use App\Exception\ApiException;
use App\Facades\Log;
use App\Utils\TraceId;
use Hyperf\ExceptionHandler\ExceptionHandler;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class ApiExceptionHandler extends ExceptionHandler
{
    public function handle(Throwable $throwable, ResponseInterface $response)
    {
        $this->stopPropagation();

        /** @var ApiException; $throwable */
        Log::error('ApiException', [
            'log_id'  => TraceId::getId(),
            'code'    => $throwable->getCode(),
            'message' => $throwable->getMessage(),
            'file'    => $throwable->getFile() . ':' . $throwable->getLine(),
            'trace'   => $throwable->getTraceAsString(),
        ]);

        return app('Json')->fail($throwable->getMessage(),  (int) $throwable->getCode());
    }

    public function isValid(Throwable $throwable): bool
    {
        return $throwable instanceof ApiException;
    }
}
