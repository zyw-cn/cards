<?php

declare(strict_types=1);

namespace App\Game\Hzmj\Action;

use App\Exception\ApiException;
use App\Facades\Log;
use App\Game\Contract\GameActionInterface;
use App\Game\Contract\GameContext;
use App\Game\Hzmj\Play\TurnHandler;
use App\Game\Platform\Timeout\RoomTimeoutService;
use App\Service\Game\GamePlayCardService;
use App\Service\Game\GameRoundService;
use App\Service\Game\RoomMemberService;
use App\Utils\WebSocketSession;
use Hyperf\DbConnection\Db;
use function Hyperf\Support\make;

/**
 * Hzmj.Op — 出牌 / 过 / 碰 / 杠 / 自摸胡
 *
 * body: op = discard|pass|pong|kong|win；discard 时另传 tile(int)
 */
final class OpAction implements GameActionInterface
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
        $op = strtolower(trim((string) ($body['op'] ?? $body['action'] ?? '')));
        $tile = isset($body['tile']) ? (int) $body['tile'] : null;

        if ($playerId <= 0) {
            return ['code' => 401, 'message' => '请先登录', 'data' => null];
        }
        if ($op === '') {
            return ['code' => 400, 'message' => '缺少操作类型', 'data' => null];
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
                $op,
                $tile,
                $body,
                $roomMemberService
            ) {
                return make(TurnHandler::class)->execute(
                    $roomId,
                    $playerId,
                    $seatNo,
                    $op,
                    $tile,
                    $body,
                    $roomMemberService,
                    make(GameRoundService::class),
                    make(GamePlayCardService::class)
                );
            });
        } catch (ApiException $e) {
            return ['code' => 400, 'message' => $e->getMessage(), 'data' => null];
        } catch (\Throwable $e) {
            Log::error('红中麻将操作失败', [
                'room_id'   => $roomId,
                'player_id' => $playerId,
                'op'        => $op,
                'tile'      => $tile,
                'error'     => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);

            return ['code' => 500, 'message' => '操作失败，请重试', 'data' => null];
        }

        return self::buildWsResponse(
            $roomMemberService,
            $roomId,
            $fd,
            $playerId,
            $outcome['data'],
            (bool) ($outcome['roomOver'] ?? false)
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array{code: int, message: string, data: mixed, _broadcast?: array}
     */
    private static function buildWsResponse(
        RoomMemberService $roomMemberService,
        int $roomId,
        int $fd,
        int $actorPid,
        array $data,
        bool $roomOver,
    ): array {
        make(RoomTimeoutService::class)->armGameTimeout($roomId);

        $members = $roomMemberService->getActiveInRoom($roomId, 'fd,player_id');
        if ($roomOver) {
            $roomMemberService->markEndedForRoom($roomId);
        }

        $drawPrivate = is_array($data['_drawPrivate'] ?? null) ? $data['_drawPrivate'] : null;
        $waitOpsBySeat = is_array($data['_waitOpsBySeat'] ?? null) ? $data['_waitOpsBySeat'] : null;
        unset($data['_drawPrivate'], $data['_waitOpsBySeat']);

        $actorData = $data;
        $public = self::publicPayload($data);

        // 出牌后碰/杠问询：按座位分发 myWaitOps
        if ($waitOpsBySeat !== null && $waitOpsBySeat !== []) {
            $perFd = [];
            $seatByPid = [];
            foreach ($members as $m) {
                $seatByPid[(int) ($m['player_id'] ?? 0)] = (int) ($m['seat_no'] ?? -1);
            }
            // members 可能没带 seat_no，从房间再取
            $withSeat = $roomMemberService->getActiveInRoom($roomId, 'fd,player_id,seat_no');
            foreach ($withSeat as $m) {
                $mFd = (int) ($m['fd'] ?? 0);
                $mPid = (int) ($m['player_id'] ?? 0);
                $mSeat = (int) ($m['seat_no'] ?? -1);
                if ($mFd <= 0) {
                    continue;
                }
                $payload = $mPid === $actorPid ? $actorData : $public;
                $ops = $waitOpsBySeat[(string) $mSeat] ?? [];
                if ($ops !== []) {
                    $payload = $public;
                    if ($mPid === $actorPid) {
                        $payload = $actorData;
                    }
                    $payload['myWaitOps'] = array_values(array_map('intval', $ops));
                } else {
                    $payload['myWaitOps'] = [];
                }
                $perFd[$mFd] = ['code' => 0, 'message' => 'ok', 'data' => $payload];
            }

            return [
                'code'       => 0,
                'message'    => 'ok',
                'data'       => $actorData,
                '_broadcast' => ['perFd' => $perFd],
            ];
        }

        // 下家摸牌：给摸牌者补私有字段
        $perFd = [];
        if ($drawPrivate !== null) {
            $drawPid = (int) ($drawPrivate['playerId'] ?? 0);
            foreach ($members as $m) {
                $mFd = (int) ($m['fd'] ?? 0);
                $mPid = (int) ($m['player_id'] ?? 0);
                if ($mFd <= 0) {
                    continue;
                }
                if ($mPid === $drawPid) {
                    $priv = $public;
                    $priv['myTiles'] = $drawPrivate['myTiles'] ?? [];
                    $priv['drawnTile'] = $drawPrivate['drawnTile'] ?? null;
                    $priv['myWaitOps'] = $drawPrivate['myWaitOps'] ?? [];
                    $priv['handCount'] = is_array($priv['myTiles']) ? count($priv['myTiles']) : ($public['handCount'] ?? 0);
                    $perFd[$mFd] = ['code' => 0, 'message' => 'ok', 'data' => $priv];
                } elseif ($mPid === $actorPid) {
                    $perFd[$mFd] = ['code' => 0, 'message' => 'ok', 'data' => $actorData];
                } else {
                    $perFd[$mFd] = ['code' => 0, 'message' => 'ok', 'data' => $public];
                }
            }

            return [
                'code'       => 0,
                'message'    => 'ok',
                'data'       => $actorData,
                '_broadcast' => ['perFd' => $perFd],
            ];
        }

        $targetFds = $roomMemberService->otherFds($members, $fd);

        return [
            'code'       => 0,
            'message'    => 'ok',
            'data'       => $actorData,
            '_broadcast' => [
                'targetFds' => array_values(array_unique($targetFds)),
                'payload'   => [
                    'code'    => 0,
                    'message' => 'ok',
                    'data'    => $public,
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function publicPayload(array $data): array
    {
        unset(
            $data['myTiles'],
            $data['myWaitOps'],
            $data['drawnTile'],
            $data['myCards'],
            $data['autoHosted'],
            $data['hostedIndex'],
            $data['_drawPrivate'],
            $data['_waitOpsBySeat']
        );

        return $data;
    }
}
