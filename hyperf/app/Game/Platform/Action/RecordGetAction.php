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
 * Record.Get — 拉取回放整包（仅参与者可看）
 *
 * body: { recordId: int }
 */
final class RecordGetAction implements GameActionInterface
{
    public function handle(GameContext $ctx): array
    {
        $body = $ctx->body;
        $playerId = WebSocketSession::uid($body);
        if ($playerId <= 0) {
            return ['code' => 401, 'message' => '请先登录', 'data' => null];
        }

        $recordId = (int) ($body['recordId'] ?? $body['record_id'] ?? $body['id'] ?? 0);
        if ($recordId <= 0) {
            return ['code' => 400, 'message' => '缺少录像ID', 'data' => null];
        }

        $pack = make(GameRecordService::class)->getPlayback($recordId, $playerId);
        if ($pack === null) {
            return ['code' => 404, 'message' => '录像不存在或无权查看', 'data' => null];
        }

        return [
            'code'    => 0,
            'message' => 'ok',
            'data'    => array_merge(['callback' => RecordCallBack::GET], $pack),
        ];
    }
}
