<?php

declare(strict_types=1);

namespace App\Game\Dou\Action;

use App\Exception\ApiException;
use App\Facades\Log;
use App\Game\Card\CardCode;
use App\Game\Contract\GameActionInterface;
use App\Game\Contract\GameContext;
use App\Game\Dou\Play\PlayTurnHandler;
use App\Game\Platform\Timeout\RoomTimeoutService;
use App\Service\Game\GamePlayCardService;
use App\Service\Game\GameRoundService;
use App\Service\Game\RoomMemberService;
use App\Utils\WebSocketDebug;
use App\Utils\WebSocketSession;
use Hyperf\DbConnection\Db;
use function Hyperf\Support\make;

/**
 * Dou.Play — 出牌/不出/结算（入口与 WS 组包）
 */
final class PlayAction implements GameActionInterface
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
        $played = self::normalizePlayedCards($body);

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
            $outcome = Db::transaction(function () use (
                $roomId,
                $playerId,
                $seatNo,
                $played,
                $body,
                $roomMemberService
            ) {
                return make(PlayTurnHandler::class)->execute(
                    $roomId,
                    $playerId,
                    $seatNo,
                    $played,
                    $body,
                    $roomMemberService,
                    make(GameRoundService::class),
                    make(GamePlayCardService::class)
                );
            });
        } catch (ApiException $e) {
            WebSocketDebug::log('play_reject', [
                'uid'     => $playerId,
                'seat'    => $seatNo,
                'cards'   => $played,
                'message' => $e->getMessage(),
            ]);

            return ['code' => 400, 'message' => $e->getMessage(), 'data' => null];
        } catch (\Throwable $e) {
            Log::error('出牌失败', [
                'room_id'   => $roomId,
                'player_id' => $playerId,
                'error'     => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);

            return ['code' => 500, 'message' => '出牌失败，请重试', 'data' => null];
        }

        return self::buildWsResponse(
            $roomMemberService,
            $roomId,
            $fd,
            $outcome['data'],
            (bool) ($outcome['roomOver'] ?? false)
        );
    }

    /**
     * @param array<string, mixed> $body
     * @return list<string>
     */
    private static function normalizePlayedCards(array $body): array
    {
        $played = $body['cards'] ?? [];
        if (! is_array($played)) {
            $played = [];
        }

        return array_values(array_filter(array_map(
            static fn ($c) => CardCode::normalize((string) $c),
            $played
        ), static fn (string $s): bool => $s !== ''));
    }

    /**
     * @param array<string, mixed> $data
     * @return array{code: int, message: string, data: mixed, _broadcast?: array}
     */
    private static function buildWsResponse(
        RoomMemberService $roomMemberService,
        int $roomId,
        int $fd,
        array $data,
        bool $roomOver,
    ): array {
        make(RoomTimeoutService::class)->armGameTimeout($roomId);

        $members = $roomMemberService->getActiveInRoom($roomId, 'fd,player_id');
        $targetFds = $roomMemberService->otherFds($members, $fd);

        if ($roomOver) {
            $roomMemberService->markEndedForRoom($roomId);
        }

        return [
            'code'       => 0,
            'message'    => 'ok',
            'data'       => $data,
            '_broadcast' => [
                'targetFds' => array_values(array_unique($targetFds)),
                'payload'   => [
                    'code'    => 0,
                    'message' => 'ok',
                    'data'    => $roomMemberService->publicPlayPayload($data),
                ],
            ],
        ];
    }
}
