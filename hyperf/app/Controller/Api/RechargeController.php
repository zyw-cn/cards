<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Controller\AbstractController;
use App\Middleware\JwtAuthMiddleware;
use App\Request\RechargeRequest;
use App\Service\Member\RechargeService;
use Hyperf\Context\Context;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\RequestMapping;
use Hyperf\Validation\Annotation\Scene;
use Psr\Http\Message\ResponseInterface;

#[Controller(prefix: '/api/recharge')]
#[Middleware(JwtAuthMiddleware::class)]
class RechargeController extends AbstractController
{
    #[Inject]
    protected RechargeService $rechargeService;

    #[RequestMapping(path: 'config', methods: 'post')]
    public function config(): ResponseInterface
    {
        return app('Json')->data($this->rechargeService->payConfig());
    }

    #[Scene(scene: 'submit')]
    #[RequestMapping(path: 'submit', methods: 'post')]
    public function submit(RechargeRequest $request): ResponseInterface
    {
        var_dump("1111111111111111111");
        $memberId = (int) Context::get('member_id');
        $file = $request->file('screenshot');
        if (! $file) {
            return app('Json')->fail('请上传转账截图');
        }

        var_dump($file);
        $data = $this->rechargeService->submitRecharge(
            $memberId,
            (float) $request->input('money'),
            (int) $request->input('channel'),
            $file,
        );

        return app('Json')->success('已提交，待后台审核');
    }

    #[RequestMapping(path: 'list', methods: 'post')]
    public function list(): ResponseInterface
    {
        $memberId = (int) Context::get('member_id');

        return app('Json')->data($this->rechargeService->rechargeList($memberId));
    }

    #[Scene(scene: 'cancel')]
    #[RequestMapping(path: 'cancel', methods: 'post')]
    public function cancel(RechargeRequest $request): ResponseInterface
    {
        $memberId = (int) Context::get('member_id');
        $this->rechargeService->cancelRecharge($memberId, (int) $request->input('id'));

        return app('Json')->success('已撤销');
    }
}
