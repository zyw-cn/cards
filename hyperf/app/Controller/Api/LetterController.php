<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Controller\AbstractController;
use App\Middleware\JwtAuthMiddleware;
use App\Service\Member\LetterService;
use Hyperf\Context\Context;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;
use Psr\Http\Message\ResponseInterface;

/**
 * 大厅站内信（无已读/删除）
 *
 * POST /api/letter/list
 *   scope=public  全员公告（顶部跑马灯）
 *   scope=private 私人站内信（底部邮件）
 *   scope=all     两者（兼容旧客户端）
 */
#[Controller(prefix: '/api/letter')]
#[Middleware(JwtAuthMiddleware::class)]
class LetterController extends AbstractController
{
    #[Inject]
    protected LetterService $letterService;

    #[PostMapping(path: 'list')]
    public function list(): ResponseInterface
    {
        $memberId = (int) Context::get('member_id');
        $page = (int) $this->request->input('page', 1);
        $pageSize = (int) $this->request->input('pageSize', 20);
        $scope = (string) $this->request->input('scope', LetterService::SCOPE_ALL);

        return app('Json')->data($this->letterService->listForMember($memberId, $page, $pageSize, $scope));
    }
}
