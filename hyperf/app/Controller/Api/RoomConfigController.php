<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Controller\AbstractController;
use App\Middleware\JwtAuthMiddleware;
use App\Service\Game\RoomConfigService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\RequestMapping;
use Psr\Http\Message\ResponseInterface;

#[Controller(prefix: '/api/room-config')]
#[Middleware(JwtAuthMiddleware::class)]
class RoomConfigController extends AbstractController
{
    #[Inject]
    protected RoomConfigService $roomConfigService;

    #[RequestMapping(path: 'list', methods: 'post')]
    public function list(): ResponseInterface
    {
        return app('Json')->data($this->roomConfigService->configList());
    }

    #[RequestMapping(path: 'config', methods: 'post')]
    public function config(): ResponseInterface
    {
        return app('Json')->data($this->roomConfigService->configList());
    }
}