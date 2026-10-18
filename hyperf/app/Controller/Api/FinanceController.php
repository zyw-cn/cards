<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Controller\AbstractController;
use App\Middleware\JwtAuthMiddleware;
use App\Service\Member\FinanceService;
use Hyperf\Context\Context;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\RequestMapping;
use Psr\Http\Message\ResponseInterface;

#[Controller(prefix: '/api/finance')]
#[Middleware(JwtAuthMiddleware::class)]
class FinanceController extends AbstractController
{
    #[Inject]
    protected FinanceService $financeService;

    /**
     * 资金流水列表.
     *
     * POST /api/finance/flow
     * Body: flow_type(0=全部,1=充值,2=提现,3=历史购卡,4=游戏获胜,5=游戏结算,6=平台费用), page, page_size
     */
    #[RequestMapping(path: 'flow', methods: 'post')]
    public function flow(): ResponseInterface
    {
        $memberId = (int) Context::get('member_id');
        $flowType = (int) $this->request->input('flow_type', 0);
        $page = (int) $this->request->input('page', 1);
        $pageSize = (int) $this->request->input('page_size', 10);

        $data = $this->financeService->flowList($memberId, $flowType, $page, $pageSize);

        return app('Json')->data($data);
    }
}
