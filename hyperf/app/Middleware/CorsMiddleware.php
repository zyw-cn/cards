<?php

declare(strict_types=1);

namespace App\Middleware;

use Hyperf\Contract\ConfigInterface;
use Hyperf\HttpMessage\Base\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class CorsMiddleware implements MiddlewareInterface
{
    public function __construct(protected ConfigInterface $config)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');

        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            return $this->buildPreflightResponse($origin);
        }

        return $this->withCorsHeaders($handler->handle($request), $origin);
    }

    protected function buildPreflightResponse(string $origin): ResponseInterface
    {
        $response = new Response(204);

        return $this->withCorsHeaders($response, $origin, true);
    }

    protected function withCorsHeaders(
        ResponseInterface $response,
        string $origin,
        bool $isPreflight = false,
    ): ResponseInterface {
        $allowOrigin = $this->resolveAllowOrigin($origin);
        if ($allowOrigin === null) {
            return $response;
        }

        $response = $response
            ->withHeader('Access-Control-Allow-Origin', $allowOrigin)
            ->withHeader('Vary', 'Origin');

        if ($this->config->get('cors.supports_credentials', false)) {
            $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        $methods = $this->config->get('cors.allowed_methods', []);
        if ($methods !== []) {
            $response = $response->withHeader('Access-Control-Allow-Methods', implode(', ', $methods));
        }

        $headers = $this->config->get('cors.allowed_headers', []);
        if ($headers !== []) {
            $response = $response->withHeader('Access-Control-Allow-Headers', implode(', ', $headers));
        }

        $exposed = $this->config->get('cors.exposed_headers', []);
        if ($exposed !== []) {
            $response = $response->withHeader('Access-Control-Expose-Headers', implode(', ', $exposed));
        }

        if ($isPreflight) {
            $maxAge = (int) $this->config->get('cors.max_age', 0);
            if ($maxAge > 0) {
                $response = $response->withHeader('Access-Control-Max-Age', (string) $maxAge);
            }
        }

        return $response;
    }

    protected function resolveAllowOrigin(string $origin): ?string
    {
        if ($origin === '') {
            return null;
        }

        $allowed = $this->config->get('cors.allowed_origins', ['*']);
        $supportsCredentials = (bool) $this->config->get('cors.supports_credentials', false);

        if (in_array('*', $allowed, true) && ! $supportsCredentials) {
            return '*';
        }

        if (in_array($origin, $allowed, true)) {
            return $origin;
        }

        return null;
    }
}
