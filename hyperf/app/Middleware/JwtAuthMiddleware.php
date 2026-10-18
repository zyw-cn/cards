<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Constants\ErrorCode;
use App\Facades\Log;
use Hyperf\Context\Context;
use HyperfExtension\Jwt\Contracts\JwtFactoryInterface;
use HyperfExtension\Jwt\Exceptions\JwtException;
use HyperfExtension\Jwt\Exceptions\TokenBlacklistedException;
use HyperfExtension\Jwt\Exceptions\TokenExpiredException;
use HyperfExtension\Jwt\Jwt;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class JwtAuthMiddleware implements MiddlewareInterface
{
    protected Jwt $jwt;

    public function __construct(JwtFactoryInterface $jwtFactory)
    {
        $this->jwt = $jwtFactory->make();
    }

    /**
     * 业务码约定:
     *  1001 - access_token 过期，前端应自动用 refresh_token 换取新令牌
     *  1002 - refresh_token 过期或无效，前端应跳转登录页
     *  1003 - token 无效（签名错误、格式错误等），前端应跳转登录页
     *  1004- token 类型错误（如用 refresh_token 访问业务接口）
     *  1005 - token 已登出（已加入黑名单），前端应跳转登录页
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            $this->jwt->parseToken();
            $payload = $this->jwt->getPayload();
        } catch (TokenExpiredException $e) {
            return app('Json')->fail('token 已过期',ErrorCode::ACCESS_TOKEN_EXPIRED);
        } catch (TokenBlacklistedException $e) {
            return app('Json')->fail('token 已失效，请重新登录', ErrorCode::ACCESS_TOKEN_INVALID);
        } catch (JwtException $e) {
            return app('Json')->fail('token 无效，请重新登录', ErrorCode::ACCESS_TOKEN_TYPE_ERROR);
        } catch (\Throwable $e) {
            Log::error('JwtAuthMiddleware', ['error' => $e->getMessage()]);
            return app('Json')->fail('身份认证失败', ErrorCode::REFRESH_TOKEN_EXPIRED);
        }

        if ($payload->get('token_type') !== 'access') {
            return app('Json')->fail('请使用 access_token 访问', ErrorCode::ACCESS_TOKEN_TYPE_ERROR);
        }

        $memberId = $payload->get('sub');
        Context::set('member_id', (int) $memberId);

        return $handler->handle($request);
    }

}
