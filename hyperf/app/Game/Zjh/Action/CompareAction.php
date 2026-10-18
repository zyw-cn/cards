<?php

declare(strict_types=1);

namespace App\Game\Zjh\Action;

use App\Exception\ApiException;
use App\Facades\Log;
use App\Game\Contract\GameActionInterface;
use App\Game\Contract\GameContext;
use App\Game\Zjh\CallBack;
use App\Game\Zjh\Card\ZjhCard;
use App\Game\Zjh\RoundState;
use App\Game\Zjh\ZjhConsts;
use App\Model\Game\GameRound;
use App\Service\Game\GamePlayCardService;
use App\Service\Game\GameRoundService;
use App\Service\Game\RoomMemberService;
use App\Service\Game\RoomService;
use App\Game\Platform\Timeout\RoomTimeoutService;
use App\Service\Game\ZjhChipService;
use App\Service\Game\ZjhSettleService;
use App\Utils\WebSocketSession;
use Hyperf\DbConnection\Db;
use function Hyperf\Support\make;

/**
 * Zjh.Compare — 比牌
 *
 * body.targetSeat 或 body.targetPlayerId：目标存活玩家
 * 发起方支付与跟注相同的金额入池后再比（不另收双倍）：
 * 闷牌付 unit，看牌付 unit×2；双方都看则同额。负方弃牌；剩一人则结算
 */
final class CompareAction implements GameActionInterface
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
                if ((int) $state['turnSeat'] !== $seatNo) {
                    throw new ApiException('还没轮到您操作');
                }
                if (RoundState::playerIdOf($state, $seatNo) !== $playerId) {
                    throw new ApiException('座位信息不匹配');
                }
                if (RoundState::isFolded($state, $seatNo)) {
                    throw new ApiException('已弃牌');
                }

                $targetSeat = (int) ($body['targetSeat'] ?? $body['target_seat'] ?? -1);
                if ($targetSeat < 0) {
                    $targetPid = (int) ($body['targetPlayerId'] ?? $body['target_player_id'] ?? 0);
                    foreach ($state['seatPids'] ?? [] as $sk => $pid) {
                        if ((int) $pid === $targetPid) {
                            $targetSeat = (int) $sk;
                            break;
                        }
                    }
                }
                if ($targetSeat === $seatNo) {
                    throw new ApiException('不能与自己比牌');
                }
                if (RoundState::playerIdOf($state, $targetSeat) <= 0) {
                    throw new ApiException('比牌目标不存在');
                }
                if (RoundState::isFolded($state, $targetSeat)) {
                    throw new ApiException('目标已弃牌');
                }

                $room = make(RoomService::class)->getOne(['id' => $roomId], 'id,rule');
                $caps = ZjhConsts::capsFromRule($room->rule ?? null);
                $amount = RoundState::callAmount($state, $seatNo, $caps['select_points']);
                $roundId = (int) $round->id;
                $after = make(ZjhChipService::class)->deduct(
                    $playerId,
                    $amount,
                    sprintf('炸金花比牌 房间%d 局%d', $roomId, $roundId)
                );
                $settleSvc = make(ZjhSettleService::class);
                $state = $settleSvc->applyChipIn($state, $seatNo, $amount, 'compare');

                $playCard = make(GamePlayCardService::class);
                $myCards = $playCard->getLatestHand($roundId, $playerId);
                $targetPid = RoundState::playerIdOf($state, $targetSeat);
                $targetCards = $playCard->getLatestHand($roundId, $targetPid);
                $cmp = ZjhCard::compare(ZjhCard::evaluate($myCards), ZjhCard::evaluate($targetCards));
                // 平局：发起方负（开牌的输）
                $challengerWin = $cmp > 0;
                $loserSeat = $challengerWin ? $targetSeat : $seatNo;
                $state['folded'][] = $loserSeat;
                $state['folded'] = array_values(array_unique(array_map('intval', $state['folded'])));
                $state['turnSeat'] = RoundState::nextAliveSeat($state, $seatNo);

                return CallAction::persistAndMaybeSettle($roomId, $roundId, $state, $settleSvc, [
                    'action'   => 'compare',
                    'amount'   => $amount,
                    'seat'     => $seatNo,
                    'target'   => $targetSeat,
                    'win'      => $challengerWin,
                    'loser'    => $loserSeat,
                    'points'   => (float) $after,
                    'playerId' => $playerId,
                ]);
            });
        } catch (ApiException $e) {
            return ['code' => 400, 'message' => $e->getMessage(), 'data' => null];
        } catch (\Throwable $e) {
            Log::error('炸金花比牌失败', [
                'room_id' => $roomId, 'player_id' => $playerId, 'error' => $e->getMessage(),
            ]);

            return ['code' => 500, 'message' => '比牌失败，请重试', 'data' => null];
        }

        if (empty($body['_auto'])) {
            $roomMemberService->clearTrust($roomId, $playerId);
        }
        make(RoomTimeoutService::class)->armGameTimeout($roomId);

        return CallAction::respond($outcome, $fd, CallBack::COMPARE, $seatNo);
    }
}
