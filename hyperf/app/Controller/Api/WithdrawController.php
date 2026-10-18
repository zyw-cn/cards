<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Controller\AbstractController;
use App\Middleware\JwtAuthMiddleware;
use App\Request\WithdrawRequest;
use App\Service\Member\WithdrawService;
use Hyperf\Context\Context;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\RequestMapping;
use Hyperf\Validation\Annotation\Scene;
use Psr\Http\Message\ResponseInterface;

#[Controller(prefix: '/api/withdraw')]
#[Middleware(JwtAuthMiddleware::class)]
class WithdrawController extends AbstractController
{
    #[Inject]
    protected WithdrawService $withdrawService;

    #[RequestMapping(path: 'config', methods: 'post')]
    public function config(): ResponseInterface
    {
        return app('Json')->success('success', $this->withdrawService->payConfig());
    }

    #[Scene(scene: 'submit')]
    #[RequestMapping(path: 'submit', methods: 'post')]
    public function submit(WithdrawRequest $request): ResponseInterface
    {
        $memberId = (int) Context::get('member_id');
        $data = $this->withdrawService->submitWithdraw(
            $memberId,
            (float) $request->input('money'),
            (string) $request->input('channel'),
            (string) $request->input('real_name'),
            (string) $request->input('pay_account'),
        );

        return app('Json')->success('已提交申请，预计 1-3 工作日到账', $data);
    }

    #[RequestMapping(path: 'list', methods: 'post')]
    public function list(): ResponseInterface
    {
        $memberId = (int) Context::get('member_id');
        $page = (int) $this->request->input('page', 1);
        $pageSize = (int) $this->request->input('page_size', 10);

        return app('Json')->success('success', $this->withdrawService->withdrawList($memberId, $page, $pageSize));
    }
}
