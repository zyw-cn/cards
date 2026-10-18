<?php

declare(strict_types=1);

namespace App\Game\Landlord;

use App\Exception\ApiException;
use App\Facades\Log;
use App\Game\Platform\RoomCallBack;
use App\Game\Support\GameRecordHooks;
use App\Model\Game\GameRound;
use App\Service\Game\GameRoundService;
use App\Service\Game\LandLordBidService;
use App\Service\Game\RoomMemberService;
use App\Service\Game\RoomService;
use App\Game\Platform\Timeout\RoomTimeoutService;
use App\Utils\WebSocketDebug;
use App\Utils\WebSocketSession;
use Hyperf\DbConnection\Db;
use function Hyperf\Support\make;

/**
 * 斗地主系共享叫地主 / 抢地主逻辑（Dou / Lz 仅变体类不同）
 *
 * body.score：
 * - 0：不叫 / 不抢
 * - 1：叫地主（尚无人叫时）
 * - maxBid+1：抢地主
 */
final class SharedBidAction
{
    /**
     * @param array<string, mixed> $body
     * @return array{code: int, message: string, data: mixed, _broadcast?: array}
     */
    public function run(array $body, LandlordVariant $v): array
    {
        $playerId = WebSocketSession::uid($body);
        $fd = (int) ($body['fd'] ?? 0);
        $score = (int) ($body['score'] ?? 0);

        $roomMemberService = make(RoomMemberService::class);
        $gameRoundService = make(GameRoundService::class);
        $landLordBidService = make(LandLordBidService::class);

        $member = $roomMemberService->getActiveByPlayer($playerId);
        if ($member === null) {
            return ['code' => 400, 'message' => '您还未加入任何房间', 'data' => null];
        }
        $roomId = (int) $member->room_id;
        $seatNo = (int) ($member->seat_no ?? 0);

        $roundState = $v->roundState;
        $bidRule = $v->bidRule;
        $dealBootstrap = $v->dealBootstrap;
        $startGameAction = $v->startGameAction;

        try {
            $outcome = Db::transaction(function () use (
                $roomId,
                $playerId,
                $seatNo,
                $score,
                $gameRoundService,
                $landLordBidService,
                $v,
                $roundState,
                $bidRule,
                $dealBootstrap
            ) {
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
                $state = $roundState::decode($round->extra ?? null);
                if (($state['phase'] ?? '') !== $v->phaseGrab) {
                    throw new ApiException('当前不是叫地主阶段');
                }
                if ((int) $round->master_id !== 0) {
                    throw new ApiException('本局地主已产生');
                }

                $roundId = (int) $round->id;
                $loaded = $landLordBidService->loadBidActions($roundId);
                $first = $loaded['first'];
                $actions = $loaded['actions'];

                if (! $bidRule::isAllowedScore($score, $actions)) {
                    throw new ApiException($score > 0 ? '叫抢不合法' : '操作不合法');
                }

                $status = $bidRule::status($first, $actions);
                if ($status['decided'] || $status['next'] === null) {
                    throw new ApiException('本局叫地主已结束');
                }
                if ($seatNo !== (int) $status['next']) {
                    throw new ApiException(
                        $bidRule::maxBid($actions) > 0 ? '还没轮到您抢地主' : '还没轮到您叫地主'
                    );
                }

                $action = $bidRule::actionOf($score, $actions);
                $actions[] = ['seat' => $seatNo, 'player_id' => $playerId, 'score' => $score];
                $next = $bidRule::status($first, $actions);
                $multiple = $landLordBidService->calcMultiple($actions);

                $landLordBidService->save([
                    'game_round_id' => $roundId,
                    'seq'           => count($actions),
                    'seat_no'       => $seatNo,
                    'member_id'     => $playerId,
                    'action'        => $action,
                    'score'         => $score,
                    'multiple'      => $multiple,
                ]);

                $state['grabs'] = $actions;
                $state['grabTurn'] = (int) ($next['next'] ?? $seatNo);
                $state['landlordMultiple'] = $multiple;
                if ($score > 0) {
                    unset($state['grabStartedAt']);
                } elseif (! isset($state['grabStartedAt']) || (int) $state['grabStartedAt'] <= 0) {
                    $state['grabStartedAt'] = time();
                }

                if ($next['redraw']) {
                    // 三家都不叫：结束本局，事务外自动重开叫抢
                    $gameRoundService->update($roundId, [
                        'status'    => GameRound::STATUS_ENDED,
                        'master_id' => 0,
                        'extra'     => $roundState::encode($state),
                    ]);

                    $room = make(RoomService::class)->getOne(['id' => $roomId], 'id,room_num');

                    return [
                        'type'     => 'redraw',
                        'seat'     => $seatNo,
                        'score'    => $score,
                        'room_num' => (string) ($room->room_num ?? ''),
                        'round_id' => $roundId,
                        'multiple' => $multiple,
                        'room_id'  => $roomId,
                    ];
                }

                if (! $next['decided']) {
                    $gameRoundService->update($roundId, ['extra' => $roundState::encode($state)]);

                    return [
                        'type'     => 'continue',
                        'seat'     => $seatNo,
                        'score'    => $score,
                        'nextSeat' => (int) $next['next'],
                        'maxBid'   => (int) $next['maxBid'],
                        'multiple' => $multiple,
                        'action'   => $action,
                        'stage'    => (string) ($next['stage'] ?? 'call'),
                        'round_id' => $roundId,
                    ];
                }

                $winner = $bidRule::pickLandlord($actions);
                if ($winner === null) {
                    throw new ApiException('未能决出地主');
                }

                $landlordSeat = (int) $winner['seat'];
                $landlordPid = (int) $winner['player_id'];
                $landlordMultiple = max(1, $multiple);

                // 定庄后底牌归地主，进入加倍阶段（地主与农民均可选）
                $awarded = $dealBootstrap::awardLandlordAndWaitDouble(
                    $roomId,
                    $roundId,
                    $state,
                    $landlordPid,
                    $landlordSeat,
                    $landlordMultiple
                );

                $room = make(RoomService::class)->getOne(['id' => $roomId], 'id,room_num');

                return [
                    'type'             => 'double',
                    'seat'             => $seatNo,
                    'score'            => $score,
                    'action'           => $action,
                    'landlordSeat'     => $landlordSeat,
                    'landlordPid'      => $landlordPid,
                    'landlordMultiple' => $landlordMultiple,
                    'players'          => $awarded['players'],
                    'remain'           => $awarded['remain'],
                    'doubleSeconds'    => $bidRule::doubleRemainSeconds($awarded['state'] ?? []),
                    'room_num'         => (string) ($room->room_num ?? ''),
                    'round_id'         => $roundId,
                ];
            });
        } catch (ApiException $e) {
            WebSocketDebug::log('bid_reject', [
                'uid'     => $playerId,
                'seat'    => $seatNo,
                'score'   => $score,
                'message' => $e->getMessage(),
            ]);

            return ['code' => 400, 'message' => $e->getMessage(), 'data' => null];
        } catch (\Throwable $e) {
            Log::error('叫地主失败', [
                'room_id'   => $roomId,
                'player_id' => $playerId,
                'error'     => $e->getMessage(),
            ]);

            return ['code' => 500, 'message' => '叫地主失败，请重试', 'data' => null];
        }

        if (empty($body['_auto'])) {
            make(RoomMemberService::class)->clearTrust($roomId, $playerId);
        }
        make(RoomTimeoutService::class)->armGameTimeout($roomId);

        $recordRoundId = (int) ($outcome['round_id'] ?? 0);
        if ($recordRoundId > 0) {
            GameRecordHooks::append($recordRoundId, 'bid', $seatNo, -1, [], ['score' => $score]);
            if (($outcome['type'] ?? '') === 'double') {
                $remainCards = array_values($outcome['remain'] ?? []);
                GameRecordHooks::append(
                    $recordRoundId,
                    'set_landlord',
                    (int) ($outcome['landlordSeat'] ?? -1),
                    -1,
                    $remainCards,
                    [
                        'landlordSeat' => (int) ($outcome['landlordSeat'] ?? -1),
                        'multiple'     => (int) ($outcome['landlordMultiple'] ?? 1),
                    ]
                );
            }
        }

        $members = $roomMemberService->getActiveInRoom($roomId, 'fd,player_id,seat_no');

        if ($outcome['type'] === 'redraw') {
            $handler = make($v->timeoutHandler);
            if ($handler->shouldDissolveGrabNoCall($roomId)) {
                make(RoomTimeoutService::class)->dissolveRoom(
                    $roomId,
                    '长时间无人叫地主，房间已解散'
                );

                return [
                    'code'    => 0,
                    'message' => 'ok',
                    'data'    => [
                        'callback' => RoomCallBack::ROOM_DISSOLVE,
                        'roomId'   => (string) ($outcome['room_num'] ?? ''),
                        'reason'   => '长时间无人叫地主，房间已解散',
                    ],
                ];
            }

            // 自动重发牌进入下一局叫抢（保留首次进入叫抢的时间，用于长时间无人叫地主解散）
            $preserveStarted = 0;
            $endedRoundId = (int) ($outcome['round_id'] ?? 0);
            if ($endedRoundId > 0) {
                $endedRow = $gameRoundService->getOne(['id' => $endedRoundId], 'extra');
                if ($endedRow !== null) {
                    $endedState = $roundState::decode($endedRow->extra ?? null);
                    $preserveStarted = (int) ($endedState['grabStartedAt'] ?? 0);
                }
            }
            $redeal = $startGameAction::redealGrabRound(
                (int) ($outcome['room_id'] ?? $roomId),
                $playerId,
                $preserveStarted > 0 ? $preserveStarted : null
            );
            if ($redeal !== null) {
                make(RoomTimeoutService::class)->armGameTimeout($roomId);
                $lastBid = [
                    'index'  => $outcome['seat'],
                    'score'  => $outcome['score'],
                    'action' => 0,
                ];
                // 全桌同步最后一手「不叫」+ 重发标记（data 与 perFd 都要带）
                if (is_array($redeal['data'] ?? null)) {
                    $redeal['data']['lastBid'] = $lastBid;
                    $redeal['data']['redraw'] = true;
                    $redeal['data']['autoRedeal'] = true;
                    $redeal['data']['reason'] = '三家都不叫，重新发牌';
                }
                foreach ($redeal['_broadcast']['perFd'] ?? [] as $bfd => $bitem) {
                    if (! is_array($bitem['data'] ?? null)) {
                        continue;
                    }
                    $redeal['_broadcast']['perFd'][$bfd]['data']['lastBid'] = $lastBid;
                    $redeal['_broadcast']['perFd'][$bfd]['data']['redraw'] = true;
                    $redeal['_broadcast']['perFd'][$bfd]['data']['autoRedeal'] = true;
                    $redeal['_broadcast']['perFd'][$bfd]['data']['reason'] = '三家都不叫，重新发牌';
                }

                return $redeal;
            }

            // 人数不足等：回等待
            $data = [
                'callback' => $v->callbackBid,
                'phase'    => 'wait',
                'redraw'   => true,
                'reason'   => '三家都不叫，请重新准备',
                'lastBid'  => [
                    'index'  => $outcome['seat'],
                    'score'  => $outcome['score'],
                    'action' => 0,
                ],
                'multiple' => $outcome['multiple'] ?? 1,
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

        if ($outcome['type'] === 'continue') {
            $data = [
                'callback'    => $v->callbackBid,
                'phase'       => $v->phaseGrab,
                'nextBidTurn' => $outcome['nextSeat'],
                'maxBid'      => $outcome['maxBid'],
                'multiple'    => $outcome['multiple'],
                'stage'       => $outcome['stage'] ?? ($outcome['maxBid'] > 0 ? 'rob' : 'call'),
                'lastBid'     => [
                    'index'  => $outcome['seat'],
                    'score'  => $outcome['score'],
                    'action' => $outcome['action'],
                ],
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

        // 决出地主：底牌亮开，进入加倍（三家均可选）
        $publicSeats = [];
        $perFd = [];
        $selfData = null;
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
                // 定庄后进加倍：用 DOUBLE 回调，避免前端仍按叫抢逻辑处理
                'callback'       => $v->callbackDouble,
                'phase'          => $v->phaseDouble,
                'roomId'         => $outcome['room_num'],
                'roundId'        => $outcome['round_id'],
                'landlordIndex'  => $outcome['landlordSeat'],
                // 三家同时加倍，无 currentIndex / doubleTurn
                'currentIndex'   => -1,
                'doubleTurn'     => -1,
                'doubleSeconds'  => (int) ($outcome['doubleSeconds'] ?? 10),
                'multiple'       => $outcome['landlordMultiple'],
                'bottom'         => $outcome['remain'],
                'bottomRevealed' => true,
                'myCards'        => $cards,
                'doubleScores'   => $v->doubleScores,
                // 空对象，避免 PHP [] 被 JSON 编成数组导致前端误判
                'farmerDoubles'  => new \stdClass(),
                'lastBid'        => [
                    'index'  => $outcome['seat'],
                    'score'  => $outcome['score'],
                    'action' => $outcome['action'] ?? $bidRule::actionOf((int) $outcome['score'], []),
                ],
                'players'        => [],
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
        foreach ($outcome['players'] as $p) {
            $mfd = (int) ($p['fd'] ?? 0);
            if ($mfd > 0 && isset($perFd[$mfd])) {
                $perFd[$mfd]['data']['myCards'] = $p['cards'] ?? [];
            }
            if ((int) ($p['player_id'] ?? 0) === $playerId && $selfData !== null) {
                $selfData['myCards'] = $p['cards'] ?? [];
            }
        }

        return [
            'code'       => 0,
            'message'    => 'ok',
            'data'       => $selfData,
            '_broadcast' => ['perFd' => $perFd],
        ];
    }
}
