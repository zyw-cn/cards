<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Controller\AbstractController;
use App\Middleware\JwtAuthMiddleware;
use App\Service\Member\RoomCardService;
use App\Service\Sys\SettingService;
use Hyperf\Context\Context;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\RequestMapping;
use Psr\Http\Message\ResponseInterface;

#[Controller(prefix: '/api/room-card')]
#[Middleware(JwtAuthMiddleware::class)]
class RoomCardController extends AbstractController
{
    /** 大厅商城购卡说明，对应 h_setting.name */
    public const SHOP_TIP_KEY = 'SHOP_ROOM_CARD_TIP';

    #[Inject]
    protected RoomCardService $roomCardService;

    #[Inject]
    protected SettingService $settingService;

    /** 启用中的房卡类型（创房选局数） */
    #[RequestMapping(path: 'types', methods: 'post')]
    public function types(): ResponseInterface
    {
        return app('Json')->data([
            'list' => $this->roomCardService->listEnabledTypes(),
        ]);
    }

    /** 我的房卡（含各类型余量） */
    #[RequestMapping(path: 'mine', methods: 'post')]
    public function mine(): ResponseInterface
    {
        $memberId = (int) Context::get('member_id');
        $list = $this->roomCardService->listMine($memberId);

        return app('Json')->data([
            'list' => $list,
            'total' => $this->roomCardService->totalQuantity($memberId),
        ]);
    }

    /** 大厅商城底部购卡说明（h_setting.SHOP_ROOM_CARD_TIP） */
    #[RequestMapping(path: 'shop-tip', methods: 'post')]
    public function shopTip(): ResponseInterface
    {
        $tip = $this->settingService->get(self::SHOP_TIP_KEY);

        return app('Json')->data([
            'tip' => is_string($tip) ? $tip : '',
        ]);
    }
}
