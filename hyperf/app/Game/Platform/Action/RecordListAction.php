<?php

declare(strict_types=1);

namespace App\Game\Platform\Action;

use App\Game\Contract\GameActionInterface;
use App\Game\Contract\GameContext;
use App\Game\Platform\RecordCallBack;
use App\Service\Game\GameRecordService;
use App\Utils\WebSocketSession;
use function Hyperf\Support\make;

/**
 * Record.List — 我的战绩列表
 *
 * body: { gameType?: int, limit?: int, offset?: int }
 */
final class RecordListAction implements GameActionInterface
{
    public function handle(GameContext $ctx): array
    {
        $body = $ctx->body;
        $playerId = WebSocketSession::uid($body);
        if ($playerId <= 0) {
            return ['code' => 401, 'message' => '请先登录', 'data' => null];
        }

        $gameType = (int) ($body['gameType'] ?? $body['game_type'] ?? 0);
        $limit = (int) ($body['limit'] ?? $body['pageSize'] ?? 20);
        $offset = (int) ($body['offset'] ?? 0);
        if (isset($body['page'])) {
            $page = max(1, (int) $body['page']);
            $offset = ($page - 1) * max(1, $limit);
        }

        $list = make(GameRecordService::class)->listByMember($playerId, $gameType, $limit, $offset);

        return [
            'code'    => 0,
            'message' => 'ok',
            'data'    => [
                'callback' => RecordCallBack::LIST,
                'list'     => $list,
                'limit'    => $limit,
                'offset'   => $offset,
            ],
        ];
    }
}
