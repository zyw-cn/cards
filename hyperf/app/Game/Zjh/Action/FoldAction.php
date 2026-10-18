<?php

declare(strict_types=1);

namespace App\Game\Zjh\Action;

use App\Exception\ApiException;
use App\Facades\Log;
use App\Game\Contract\GameActionInterface;
use App\Game\Contract\GameContext;
use App\Game\Zjh\CallBack;
use App\Game\Zjh\RoundState;
use App\Game\Zjh\ZjhConsts;
use App\Model\Game\GameRound;
use App\Service\Game\GameRoundService;
use App\Service\Game\RoomMemberService;
use App\Game\Platform\Timeout\RoomTimeoutService;
use App\Service\Game\ZjhSettleService;
use App\Utils\WebSocketSession;
use Hyperf\DbConnection\Db;
use function Hyperf\Support\make;

/**
 * Zjh.Fold — 弃牌
 */
final class FoldAction implements GameActionInterface
{
    public function handle(GameContext $ctx): array
    {
        return $this->run($ctx->body);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{code: int, message: string, data: mixed, _broadcast?: array}
     */
    public function run(array $body): array
    {
        $playerId = WebSocketSession::uid($body);
        $fd = (int) ($body['fd'] ?? 0);
        if ($playerId <= 0) {
            return ['code' => 401, 'message' => '请先登录', 'data' => null];
        }

        $roomMemberService = make(RoomMemberService::class);
        $member = $roomMemberService->getActiveByPlayer($playerId);
        if ($member === null) {
            return ['code' => 400, 'message' => '您还未加入任何房间', 'data' => null];
        }
        $roomId = (int) $member->room_id;
        $seatNo = (int) ($member->seat_no ?? 0);

        try {
            $outcome = Db::transaction(function () use ($roomId, $playerId, $seatNo, $body) {
                return self::act($roomId, $playerId, $seatNo, ! empty($body['_auto']));
            });
        } catch (ApiException $e) {
            return ['code' => 400, 'message' => $e->getMessage(), 'data' => null];
        } catch (\Throwable $e) {
            Log::error('炸金花弃牌失败', [
                'room_id' => $roomId, 'player_id' => $playerId, 'error' => $e->getMessage(),
            ]);

            return ['code' => 500, 'message' => '弃牌失败，请重试', 'data' => null];
        }

        if (empty($body['_auto'])) {
            $roomMemberService->clearTrust($roomId, $playerId);
        }
        make(RoomTimeoutService::class)->armGameTimeout($roomId);

        return CallAction::respond($outcome, $fd, CallBack::FOLD, $seatNo);
    }

    /**
     * @return array<string, mixed>
     */
    public static function act(int $roomId, int $playerId, int $seatNo, bool $auto = false): array
    {
        $round = make(GameRoundService::class)->lockForUpdate(
            ['room_id' => $roomId, 'status' => GameRound::STATUS_PLAYING],
            ['id', 'extra', 'status']
        );
        if ($round === null) {
            throw new ApiException('本局尚未开始或已结束');
        }
        $state = RoundState::decode($round->extra);
        if ($state['phase'] !== ZjhConsts::PHASE_BET) {
            throw new ApiException('当前不是下注阶段');
        }
        if (RoundState::playerIdOf($state, $seatNo) !== $playerId) {
            throw new ApiException('座位信息不匹配');
        }
        if (RoundState::isFolded($state, $seatNo)) {
            throw new ApiException('已弃牌');
        }
        // 超时弃牌可不在 turn；主动弃牌须轮到自己
        if (! $auto && (int) $state['turnSeat'] !== $seatNo) {
            throw new ApiException('还没轮到您操作');
        }

        $state['folded'][] = $seatNo;
        $state['folded'] = array_values(array_unique(array_map('intval', $state['folded'])));
        if ((int) $state['turnSeat'] === $seatNo) {
            $state['turnSeat'] = RoundState::nextAliveSeat($state, $seatNo);
        }

        $roundId = (int) $round->id;

        return CallAction::persistAndMaybeSettle(
            $roomId,
            $roundId,
            $state,
            make(ZjhSettleService::class),
            ['action' => 'fold', 'amount' => '0', 'auto' => $auto, 'seat' => $seatNo, 'playerId' => $playerId]
        );
    }
}
