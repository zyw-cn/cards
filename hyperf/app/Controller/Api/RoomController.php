<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Controller\AbstractController;
use App\Exception\ApiException;
use App\Middleware\JwtAuthMiddleware;
use App\Service\Game\RoomService;
use Hyperf\Context\Context;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\RequestMapping;
use Psr\Http\Message\ResponseInterface;

#[Controller(prefix: '/api/room')]
#[Middleware(JwtAuthMiddleware::class)]
class RoomController extends AbstractController
{
    #[Inject]
    protected RoomService $roomService;

    #[RequestMapping(path: 'create', methods: 'post')]
    public function create(): ResponseInterface
    {
        $memberId = (int) Context::get('member_id');
        $gameType = (int) $this->request->input('game_type', 1);
        $num = (int) $this->request->input('num', 3);
        $rule = $this->normalizeRule($this->request->input('rule'));

        $created = $this->roomService->create(
            $memberId,
            $gameType,
            $num,
            $rule,
        );

        return app('Json')->data([
            'room_num'   => (string) $created['room_num'],
            'rule'       => (string) ($created['rule'] ?? ''),
            'game_type'  => (int) ($created['game_type'] ?? $gameType),
            'already_in' => ! empty($created['already_in']),
        ]);
    }

    /**
     * rule 统一存 JSON 字符串；入参可为 JSON 字符串或数组
     */
    private function normalizeRule(mixed $rule): string
    {
        if ($rule === null || $rule === '') {
            return '';
        }

        if (is_array($rule)) {
            $json = json_encode($rule, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                throw new ApiException('规则格式错误');
            }
            return $json;
        }

        if (! is_string($rule)) {
            throw new ApiException('规则格式错误');
        }

        $trimmed = trim($rule);
        if ($trimmed === '') {
            return '';
        }

        $decoded = json_decode($trimmed, true);
        if (! is_array($decoded)) {
            throw new ApiException('规则须为合法 JSON 字符串');
        }

        $json = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new ApiException('规则格式错误');
        }

        return $json;
    }
}
