<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Facades\Log;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class RequestLifecycleMiddleware implements MiddlewareInterface
{
    protected array $excludedRoutes = [
        // '/api/health',
        // '/api/ping',
    ];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $uri = $request->getUri()->getPath();

        if ($this->shouldSkip($request, $uri)) {
            return $handler->handle($request);
        }

        $startTime = microtime(true);
        $method    = $request->getMethod();
        $headerLog = [
            'cookie'                    => $request->getHeaderLine('cookie'),
            'accept-language'           => $request->getHeaderLine('accept-language'),
            'accept-encoding'           => $request->getHeaderLine('accept-encoding'),
            'accept'                    => $request->getHeaderLine('accept'),
            'user-agent'                => $request->getHeaderLine('user-agent'),
            'upgrade-insecure-requests' => $request->getHeaderLine('upgrade-insecure-requests'),
            'connection'                => $request->getHeaderLine('connection'),
            'host'                      => $request->getHeaderLine('host'),
            'referer'                   => $request->getHeaderLine('referer'),
            'content-type'              => $request->getHeaderLine('content-type'),
            'authorization'             => $request->getHeaderLine('authorization'),
        ];

        Log::info('request.before', [
            'method'  => $method,
            'uri'     => $uri,
            'params'  => $method === 'GET'
                ? $request->getQueryParams()
                : $request->getParsedBody(),
            'headers' => $headerLog,
        ]);

        try {
            $response = $handler->handle($request);
            $status   = $response->getStatusCode();
        } catch (\Throwable $e) {
            $status = 500;
            Log::error('request.after', [
                'method'    => $method,
                'uri'       => $uri,
                'status'    => $status,
                'duration'  => round((microtime(true) - $startTime) * 1000, 2) . 'ms',
                'exception' => $e->getMessage(),
            ]);
            throw $e;
        }

        Log::info('request.after', [
            'method'   => $method,
            'uri'      => $uri,
            'status'   => $status,
            'duration' => round((microtime(true) - $startTime) * 1000, 2) . 'ms',
        ]);

        return $response;
    }

    protected function shouldSkip(ServerRequestInterface $request, string $uri): bool
    {
        // 携带文件上传的请求跳过
        if (!empty($request->getUploadedFiles())) {
            return true;
        }

        // 自定义排除路由
        foreach ($this->excludedRoutes as $pattern) {
            if ($uri === $pattern || str_starts_with($uri, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
