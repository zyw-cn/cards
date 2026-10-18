<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Controller\AbstractController;
use App\Middleware\JwtAuthMiddleware;
use App\Service\Member\FeedbackService;
use Hyperf\Context\Context;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;
use Psr\Http\Message\ResponseInterface;

/**
 * 大厅反馈（玩家端）
 *
 * POST /api/feedback/submit   body: { content }
 * POST /api/feedback/list
 * POST /api/feedback/unread
 * POST /api/feedback/read     body: { id }  标记答复已读
 */
#[Controller(prefix: '/api/feedback')]
#[Middleware(JwtAuthMiddleware::class)]
class FeedbackController extends AbstractController
{
    #[Inject]
    protected FeedbackService $feedbackService;

    #[PostMapping(path: 'submit')]
    public function submit(): ResponseInterface
    {
        $memberId = (int) Context::get('member_id');
        $content = (string) $this->request->input('content', '');

        return app('Json')->data($this->feedbackService->submit($memberId, $content));
    }

    #[PostMapping(path: 'list')]
    public function list(): ResponseInterface
    {
        $memberId = (int) Context::get('member_id');
        $page = (int) $this->request->input('page', 1);
        $pageSize = (int) $this->request->input('pageSize', 30);

        return app('Json')->data($this->feedbackService->listForMember($memberId, $page, $pageSize));
    }

    #[PostMapping(path: 'unread')]
    public function unread(): ResponseInterface
    {
        $memberId = (int) Context::get('member_id');

        return app('Json')->data([
            'unread' => $this->feedbackService->unreadReplyCount($memberId),
        ]);
    }

    #[PostMapping(path: 'read')]
    public function read(): ResponseInterface
    {
        $memberId = (int) Context::get('member_id');
        $id = (int) $this->request->input('id', 0);

        return app('Json')->data($this->feedbackService->markReplyRead($memberId, $id));
    }
}
