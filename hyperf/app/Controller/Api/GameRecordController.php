<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Controller\AbstractController;
use App\Middleware\JwtAuthMiddleware;
use App\Service\Game\GameRecordService;
use Hyperf\Context\Context;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\RequestMapping;
use Psr\Http\Message\ResponseInterface;

#[Controller(prefix: '/api/record')]
#[Middleware(JwtAuthMiddleware::class)]
class GameRecordController extends AbstractController
{
    #[Inject]
    protected GameRecordService $gameRecordService;

    /**
     * 我的战绩列表
     *
     * POST /api/record/list
     * Body: game_type(0=全部), page, page_size
     */
    #[RequestMapping(path: 'list', methods: 'post')]
    public function list(): ResponseInterface
    {
        $memberId = (int) Context::get('member_id');
        $gameType = (int) $this->request->input('game_type', 0);
        $page = max(1, (int) $this->request->input('page', 1));
        $pageSize = (int) $this->request->input('page_size', 20);
        $pageSize = max(1, min(50, $pageSize));
        $offset = ($page - 1) * $pageSize;

        $list = $this->gameRecordService->listByMember($memberId, $gameType, $pageSize, $offset);

        return app('Json')->data([
            'list'      => $list,
            'page'      => $page,
            'page_size' => $pageSize,
        ]);
    }

    /**
     * 回放整包（仅本局参与者）
     *
     * POST /api/record/detail
     * Body: record_id
     */
    #[RequestMapping(path: 'detail', methods: 'post')]
    public function detail(): ResponseInterface
    {
        $memberId = (int) Context::get('member_id');
        $recordId = (int) $this->request->input('record_id', 0);
        if ($recordId <= 0) {
            return app('Json')->fail('缺少录像ID');
        }

        $data = $this->gameRecordService->getPlayback($recordId, $memberId);
        if ($data === null) {
            return app('Json')->fail('录像不存在或无权查看');
        }

        return app('Json')->data($data);
    }
}
