<?php

declare(strict_types=1);

namespace App\Game\Pdk\Action;

use App\Exception\ApiException;
use App\Facades\Log;
use App\Game\Contract\GameActionInterface;
use App\Game\Contract\GameContext;
use App\Game\Pdk\CallBack;
use App\Game\Pdk\PdkConsts;
use App\Game\Pdk\RoundState;
use App\Game\Pdk\Rule\DoubleRule;
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
 * Pdk.Double — 发牌后加倍（0=不加倍 / 2=加倍）
 *
 * 三家可同时选择；全选完后进入出牌。
 */
final class DoubleAction implements GameActionInterface
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
        $score = (int) ($body['score'] ?? 0);

        $roomMemberService = make(RoomMemberService::class);
        $gameRoundService = make(GameRoundService::class);

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
                $score,
                $gameRoundService,
                $roomMemberService
            ) {
                if (! DoubleRule::isAllowedScore($score)) {
                    throw new ApiException('加倍倍数不合法');
                }

                $round = $gameRoundService->lockForUpdate(
                    [
                        'room_id' => $roomId,
                        'status'  => GameRound::STATUS_PLAYING,
                    ],
                    ['id', 'master_id', 'status', 'extra']
                );
                if ($round === null) {
                    throw new ApiException('本局尚未开始');
                }
                $state = RoundState::decode($round->extra ?? null);
                if (($state['phase'] ?? '') !== PdkConsts::PHASE_DOUBLE) {
                    throw new ApiException('当前不是加倍阶段');
                }

                $firstSeat = (int) ($state['firstSeat'] ?? 0);
                $key = (string) $seatNo;
                $doubles = is_array($state['doubles'] ?? null) ? $state['doubles'] : [];
                if (isset($doubles[$key])) {
                    throw new ApiException('您已完成加倍');
                }

                $doubles[$key] = $score;
                $state['doubles'] = $doubles;
                $roundId = (int) $round->id;

                $pending = DoubleRule::pendingSeats($doubles);
                $done = $pending === [];
                // 同时加倍：不再轮流，doubleTurn 仅作兼容字段（下一位待选或 -1）
                $state['doubleTurn'] = $done ? -1 : ($pending[0] ?? -1);

                if (! $done) {
                    $gameRoundService->update($roundId, ['extra' => RoundState::encode($state)]);

                    return [
                        'type'       => 'wait',
                        'seat'       => $seatNo,
                        'score'      => $score,
                        'doubles'    => $doubles,
                        'doubleTurn' => $state['doubleTurn'],
                        'pending'    => $pending,
                        'firstSeat'  => $firstSeat,
                        'round_id'   => $roundId,
                    ];
                }

                $state['phase'] = PdkConsts::PHASE_PLAY;
                $gameRoundService->update($roundId, ['extra' => RoundState::encode($state)]);

                $members = $roomMemberService->getActiveInRoom($roomId);
                $players = [];
                $playCard = make(GamePlayCardService::class);
                foreach ($members as $m) {
                    $pid = (int) ($m['player_id'] ?? 0);
                    $seat = (int) ($m['seat_no'] ?? 0);
                    $cards = $playCard->getLatestHand($roundId, $pid);
                    $players[] = [
                        'seat_no'    => $seat,
                        'player_id'  => $pid,
                        'nickname'   => (string) ($m['nickname'] ?? ''),
                        'avatar'     => (string) ($m['avatar'] ?? ''),
                        'avatar_url' => (string) ($m['avatar_url'] ?? ''),
                        'fd'         => (int) ($m['fd'] ?? 0),
                        'points'     => (float) ($m['points'] ?? 0),
                        'cards'      => $cards,
                    ];
                }

                $room = make(RoomService::class)->getOne(['id' => $roomId], 'id,room_num');

                return [
                    'type'        => 'play',
                    'seat'        => $seatNo,
                    'score'       => $score,
                    'doubles'     => $doubles,
                    'players'     => $players,
                    'firstSeat'   => $firstSeat,
                    'room_num'    => (string) ($room->room_num ?? ''),
                    'round_id'    => $roundId,
                ];
            });
        } catch (ApiException $e) {
            return ['code' => 400, 'message' => $e->getMessage(), 'data' => null];
        } catch (\Throwable $e) {
            Log::error('跑得快加倍失败', [
                'room_id'   => $roomId,
                'player_id' => $playerId,
                'error'     => $e->getMessage(),
            ]);

            return ['code' => 500, 'message' => '加倍失败，请重试', 'data' => null];
        }

        if (empty($body['_auto'])) {
            make(RoomMemberService::class)->clearTrust($roomId, $playerId);
        }
        make(RoomTimeoutService::class)->armGameTimeout($roomId);

        GameRecordHooks::append(
            (int) ($outcome['round_id'] ?? 0),
            'double',
            $seatNo,
            -1,
            [],
            ['score' => (int) ($outcome['score'] ?? $score)]
        );

        $members = $roomMemberService->getActiveInRoom($roomId, 'fd,player_id,seat_no');

        if ($outcome['type'] === 'wait') {
            $data = [
                'callback'     => CallBack::DOUBLE,
                'phase'        => PdkConsts::PHASE_DOUBLE,
                'lastDouble'   => ['index' => $outcome['seat'], 'score' => $outcome['score']],
                'doubles'      => $outcome['doubles'],
                'doubleScores' => PdkConsts::DOUBLE_SCORES,
                'pendingSeats' => $outcome['pending'] ?? DoubleRule::pendingSeats($outcome['doubles']),
                'simultaneous' => true,
                'currentIndex' => (int) $outcome['doubleTurn'],
                'doubleTurn'   => (int) $outcome['doubleTurn'],
                'firstIndex'   => (int) $outcome['firstSeat'],
            ];

            return [
                'code'       => 0,
                'message'    => 'ok',
                'data'       => $data,
                '_broadcast' => [
                    'targetFds' => $roomMemberService->otherFds($members, $fd),
                    'payload'   => ['code' => 0, 'message' => 'ok', 'data' => $data],
                ],
            ];
        }

        $publicSeats = [];
        $perFd = [];
        $selfData = null;
        $firstSeat = (int) $outcome['firstSeat'];
        foreach ($outcome['players'] as $p) {
            $mfd = (int) ($p['fd'] ?? 0);
            $pid = (int) ($p['player_id'] ?? 0);
            $seat = (int) ($p['seat_no'] ?? 0);
            $cards = $p['cards'] ?? [];
            $publicSeats[] = [
                'seat_no'    => $seat,
                'player_id'  => $pid,
                'nickname'   => (string) ($p['nickname'] ?? ''),
                'avatar'     => (string) ($p['avatar'] ?? ''),
                'cardsCount' => is_array($cards) ? count($cards) : 0,
                'points'     => (float) ($p['points'] ?? 0),
            ];
            $payload = [
                'callback'     => CallBack::DOUBLE,
                'phase'        => PdkConsts::PHASE_PLAY,
                'roomId'       => $outcome['room_num'],
                'roundId'      => $outcome['round_id'],
                'firstIndex'   => $firstSeat,
                'currentIndex' => $firstSeat,
                'mustSpade3'   => true,
                'mustBeat'     => true,
                'doubles'      => $outcome['doubles'],
                'lastDouble'   => ['index' => $outcome['seat'], 'score' => $outcome['score']],
                'myCards'      => $cards,
                'players'      => [],
            ];
            if ($mfd > 0) {
                $perFd[$mfd] = ['code' => 0, 'message' => 'ok', 'data' => $payload];
            }
            if ($pid === $playerId) {
                $selfData = $payload;
            }
        }
        foreach ($perFd as &$item) {
            $item['data']['players'] = $publicSeats;
        }
        unset($item);
        if ($selfData !== null) {
            $selfData['players'] = $publicSeats;
        }

        return [
            'code'       => 0,
            'message'    => 'ok',
            'data'       => $selfData,
            '_broadcast' => ['perFd' => $perFd],
        ];
    }
}
