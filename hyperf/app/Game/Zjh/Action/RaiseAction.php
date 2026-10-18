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
use App\Service\Game\RoomService;
use App\Game\Platform\Timeout\RoomTimeoutService;
use App\Service\Game\ZjhChipService;
use App\Service\Game\ZjhSettleService;
use App\Utils\WebSocketSession;
use Hyperf\DbConnection\Db;
use function Hyperf\Support\make;

/**
 * Zjh.Raise — 加注（绝对金额）
 *
 * body.amount：本次加注支付额，必须落在 select_points
 * - 闷牌：amount = 新 unit，且 ≤ men_max，> 当前 unit
 * - 看牌：amount = 支付额（看注档），新 unit 取与之对应的闷注档，且 amount ≤ look_max，> 当前看跟
 */
final class RaiseAction implements GameActionInterface
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

                $room = make(RoomService::class)->getOne(['id' => $roomId], 'id,rule');
                $caps = ZjhConsts::capsFromRule($room->rule ?? null);
                $looked = RoundState::isLooked($state, $seatNo);
                $curUnit = ZjhConsts::normAmount((string) ($state['unit'] ?? '0'));
                $points = $caps['select_points'];
                $curCall = RoundState::callAmount($state, $seatNo, $points);

                // 兼容旧 multiple：按底分×倍数折算成支付额
                if (isset($body['amount']) || isset($body['unit'])) {
                    $amount = ZjhConsts::normAmount((string) ($body['amount'] ?? $body['unit']));
                } else {
                    $multiple = (int) ($body['multiple'] ?? 0);
                    $bottom = make(RoomService::class)->resolveBottomPoints($room->rule ?? null);
                    if ($multiple <= 0 || bccomp($bottom, '0', 2) <= 0) {
                        throw new ApiException('加注金额不合法');
                    }
                    $amount = ZjhConsts::normAmount(bcmul($bottom, (string) $multiple, 2));
                    if ($looked) {
                        $amount = ZjhConsts::lookCallFromUnit($amount, $points);
                    }
                }

                if ($looked) {
                    // 看加：支付 amount（档位），闷注升到对应档
                    if (bccomp($amount, $curCall, 2) <= 0) {
                        throw new ApiException('加注须大于当前跟注');
                    }
                    if (bccomp($amount, $caps['look_max'], 2) > 0) {
                        throw new ApiException('超过看牌最大跟注' . $caps['look_max']);
                    }
                    $newUnit = ZjhConsts::menUnitFromLookAmount($amount, $points);
                } else {
                    // 闷加：支付 amount = 新 unit
                    if (bccomp($amount, $curUnit, 2) <= 0) {
                        throw new ApiException('加注须大于当前闷注');
                    }
                    if (bccomp($amount, $caps['men_max'], 2) > 0) {
                        throw new ApiException('超过闷牌最大跟注' . $caps['men_max']);
                    }
                    $newUnit = $amount;
                }

                $allowed = false;
                foreach ($points as $p) {
                    if (bccomp($amount, ZjhConsts::normAmount((string) $p), 2) === 0) {
                        $allowed = true;
                        break;
                    }
                }
                if (! $allowed) {
                    throw new ApiException('加注金额必须是可选档位');
                }

                $state['unit'] = $newUnit;
                $pay = $amount;

                $roundId = (int) $round->id;
                $after = make(ZjhChipService::class)->deduct(
                    $playerId,
                    $pay,
                    sprintf('炸金花加注 房间%d 局%d', $roomId, $roundId)
                );
                $settleSvc = make(ZjhSettleService::class);
                $state = $settleSvc->applyChipIn($state, $seatNo, $pay, $looked ? 'raise' : 'men');
                $state['turnSeat'] = RoundState::nextAliveSeat($state, $seatNo);

                return CallAction::persistAndMaybeSettle($roomId, $roundId, $state, $settleSvc, [
                    'action'   => 'raise',
                    'amount'   => $pay,
                    'unit'     => $newUnit,
                    'seat'     => $seatNo,
                    'points'   => (float) $after,
                    'playerId' => $playerId,
                ]);
            });
        } catch (ApiException $e) {
            return ['code' => 400, 'message' => $e->getMessage(), 'data' => null];
        } catch (\Throwable $e) {
            Log::error('炸金花加注失败', [
                'room_id' => $roomId, 'player_id' => $playerId, 'error' => $e->getMessage(),
            ]);

            return ['code' => 500, 'message' => '加注失败，请重试', 'data' => null];
        }

        if (empty($body['_auto'])) {
            $roomMemberService->clearTrust($roomId, $playerId);
        }
        make(RoomTimeoutService::class)->armGameTimeout($roomId);

        return CallAction::respond($outcome, $fd, CallBack::RAISE, $seatNo);
    }
}
