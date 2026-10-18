<?php

declare(strict_types=1);

namespace App\Game\Zjh\Action;

use App\Exception\ApiException;
use App\Facades\Log;
use App\Game\Contract\GameActionInterface;
use App\Game\Contract\GameContext;
use App\Game\Zjh\CallBack;
use App\Game\Zjh\RoundState;
use App\Game\Zjh\Support;
use App\Game\Zjh\ZjhConsts;
use App\Game\Platform\Timeout\RoomTimeoutService;
use App\Game\Support\GameRecordHooks;
use App\Model\Game\GameRound;
use App\Service\Game\GamePlayCardService;
use App\Service\Game\GameRoundService;
use App\Service\Game\RoomMemberService;
use App\Service\Game\RoomService;
use App\Utils\WebSocketSession;
use Hyperf\DbConnection\Db;
use function Hyperf\Support\make;

/**
 * Zjh.Look — 看牌（标记已看，本人回包带手牌；广播不含牌面）
 */
final class LookAction implements GameActionInterface
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
            $outcome = Db::transaction(function () use ($roomId, $playerId, $seatNo) {
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
                    throw new ApiException('已弃牌，无法看牌');
                }
                // 自己随时可看自己的牌；看牌后跟注按 unit×2
                if (! RoundState::isLooked($state, $seatNo)) {
                    $state['looked'][] = $seatNo;
                    make(GameRoundService::class)->update((int) $round->id, [
                        'extra' => RoundState::encode($state),
                    ]);
                }
                $myCards = make(GamePlayCardService::class)->getLatestHand((int) $round->id, $playerId);
                $room = make(RoomService::class)->getOne(['id' => $roomId], 'id,room_num,rule');

                return [
                    'round_id' => (int) $round->id,
                    'room_num' => (string) ($room->room_num ?? ''),
                    'state'    => $state,
                    'myCards'  => $myCards,
                    'rule'     => $room->rule ?? null,
                    'members'  => make(RoomMemberService::class)->getActiveInRoom($roomId, 'fd,player_id'),
                ];
            });
        } catch (ApiException $e) {
            return ['code' => 400, 'message' => $e->getMessage(), 'data' => null];
        } catch (\Throwable $e) {
            Log::error('炸金花看牌失败', [
                'room_id' => $roomId, 'player_id' => $playerId, 'error' => $e->getMessage(),
            ]);

            return ['code' => 500, 'message' => '看牌失败，请重试', 'data' => null];
        }

        make(RoomTimeoutService::class)->armGameTimeout($roomId);

        GameRecordHooks::append($outcome['round_id'], 'look', $seatNo);

        $table = Support::publicTable(
            $outcome['state'],
            $outcome['room_num'],
            $outcome['round_id'],
            $outcome['rule'] ?? null
        );
        $public = array_merge($table, [
            'callback'  => CallBack::LOOK,
            'lookIndex' => $seatNo,
        ]);
        $self = array_merge($public, ['myCards' => $outcome['myCards']]);

        return [
            'code'       => 0,
            'message'    => 'ok',
            'data'       => $self,
            '_broadcast' => [
                'targetFds' => $roomMemberService->otherFds($outcome['members'], $fd),
                'payload'   => ['code' => 0, 'message' => 'ok', 'data' => $public],
            ],
        ];
    }
}
