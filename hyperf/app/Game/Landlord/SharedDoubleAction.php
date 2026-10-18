<?php

declare(strict_types=1);

namespace App\Game\Landlord;

use App\Exception\ApiException;
use App\Facades\Log;
use App\Game\Support\GameRecordHooks;
use App\Model\Game\GameRound;
use App\Service\Game\GameRoundService;
use App\Service\Game\RoomMemberService;
use App\Game\Platform\Timeout\RoomTimeoutService;
use App\Utils\WebSocketSession;
use Hyperf\DbConnection\Db;
use function Hyperf\Support\make;

/**
 * 斗地主系共享加倍逻辑（Dou / Lz：地主与农民均可选 0 / ×2）
 *
 * 对齐 QQ：定庄后三家同时可选加倍；各人时限由 doubleDeadlineAt 约束；
 * 到点未选由超时补 0；全员选完后进入出牌。
 */
final class SharedDoubleAction
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
        $autoAll = ! empty($body['_auto_all']);

        $roomMemberService = make(RoomMemberService::class);
        $gameRoundService = make(GameRoundService::class);

        $member = $roomMemberService->getActiveByPlayer($playerId);
        if ($member === null) {
            return ['code' => 400, 'message' => '您还未加入任何房间', 'data' => null];
        }
        $roomId = (int) $member->room_id;
        $seatNo = (int) ($member->seat_no ?? 0);

        $roundState = $v->roundState;
        $bidRule = $v->bidRule;
        $dealBootstrap = $v->dealBootstrap;

        try {
            $outcome = Db::transaction(function () use (
                $roomId,
                $playerId,
                $seatNo,
                $score,
                $autoAll,
                $gameRoundService,
                $v,
                $roundState,
                $bidRule,
                $dealBootstrap
            ) {
                if (! $autoAll && ! $bidRule::isAllowedDoubleScore($score)) {
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
                $state = $roundState::decode($round->extra ?? null);
                if (($state['phase'] ?? '') !== $v->phaseDouble) {
                    throw new ApiException('当前不是加倍阶段');
                }

                $landlordSeat = (int) ($state['landlordSeat'] ?? -1);
                $landlordPid = (int) ($round->master_id ?? 0);
                $key = (string) $seatNo;
                $maxPlayers = 3;

                if ($autoAll) {
                    // 超时：所有未选座位一律不加倍
                    for ($s = 0; $s < $maxPlayers; $s++) {
                        $sk = (string) $s;
                        if (! isset($state['farmerDoubles'][$sk])) {
                            $state['farmerDoubles'][$sk] = 0;
                        }
                    }
                    $score = 0;
                } else {
                    if (isset($state['farmerDoubles'][$key])) {
                        throw new ApiException('您已完成加倍');
                    }
                    $state['farmerDoubles'][$key] = $score;
                }

                $roundId = (int) $round->id;
                $done = $bidRule::isDoubleComplete($state['farmerDoubles'], $maxPlayers);
                $state['doubleTurn'] = -1;

                $landlordMultiple = max(1, (int) ($state['landlordMultiple'] ?? 1));
                $state['landlordMultiple'] = $landlordMultiple;
                $doubleSeconds = $bidRule::doubleRemainSeconds($state);

                if (! $done) {
                    $gameRoundService->update($roundId, ['extra' => $roundState::encode($state)]);

                    return [
                        'type'             => 'wait',
                        'seat'             => $seatNo,
                        'score'            => $score,
                        'farmerDoubles'    => $state['farmerDoubles'],
                        'landlordMultiple' => $landlordMultiple,
                        'doubleSeconds'    => $doubleSeconds,
                        'room_num'         => '',
                        'round_id'         => $roundId,
                    ];
                }

                $started = $dealBootstrap::beginPlayAfterDouble($roomId, $roundId, $state, $landlordPid);

                $room = make(\App\Service\Game\RoomService::class)->getOne(['id' => $roomId], 'id,room_num');

                return [
                    'type'             => 'play',
                    'seat'             => $seatNo,
                    'score'            => $score,
                    'farmerDoubles'    => $state['farmerDoubles'],
                    'players'          => $started['players'],
                    'remain'           => $started['remain'],
                    'landlordSeat'     => $landlordSeat,
                    'landlordPid'      => $landlordPid,
                    'landlordMultiple' => $landlordMultiple,
                    'room_num'         => (string) ($room->room_num ?? ''),
                    'round_id'         => $roundId,
                ];
            });
        } catch (ApiException $e) {
            return ['code' => 400, 'message' => $e->getMessage(), 'data' => null];
        } catch (\Throwable $e) {
            Log::error('加倍失败', [
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

        $recordRoundId = (int) ($outcome['round_id'] ?? 0);
        if ($recordRoundId > 0 && empty($body['_auto_all'])) {
            GameRecordHooks::append(
                $recordRoundId,
                'double',
                $seatNo,
                -1,
                [],
                ['double' => (int) ($outcome['score'] ?? $score)]
            );
        }

        $members = $roomMemberService->getActiveInRoom($roomId, 'fd,player_id,seat_no');

        if ($outcome['type'] === 'wait') {
            $data = [
                'callback'      => $v->callbackDouble,
                'phase'         => $v->phaseDouble,
                'lastDouble'    => ['index' => $outcome['seat'], 'score' => $outcome['score']],
                'farmerDoubles' => $outcome['farmerDoubles'],
                'doubleScores'  => $v->doubleScores,
                // 同时加倍：无「当前轮到谁」；前端用 farmerDoubles 判断自己是否已选
                'currentIndex'  => -1,
                'doubleTurn'    => -1,
                'doubleSeconds' => (int) ($outcome['doubleSeconds'] ?? 0),
                // 桌面倍数仅叫抢；个人加倍看 farmerDoubles，勿与叫抢混乘
                'multiple'      => $bidRule::tableMultiple((int) ($outcome['landlordMultiple'] ?? 1)),
                'bidMultiple'   => (int) ($outcome['landlordMultiple'] ?? 1),
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

        // 加倍完成 → 出牌（手牌已在叫抢/定庄时就绪）
        $publicSeats = [];
        $perFd = [];
        $selfData = null;
        $bidMultiple = (int) ($outcome['landlordMultiple'] ?? 1);
        $displayMultiple = $bidRule::tableMultiple($bidMultiple);
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
                'callback'       => $v->callbackDouble,
                'phase'          => $v->phasePlay,
                'roomId'         => $outcome['room_num'],
                'roundId'        => $outcome['round_id'],
                'landlordIndex'  => $outcome['landlordSeat'],
                'currentIndex'   => $outcome['landlordSeat'],
                'multiple'       => $displayMultiple,
                'bidMultiple'    => $bidMultiple,
                'farmerDoubles'  => $outcome['farmerDoubles'],
                'lastDouble'     => [
                    'index' => (int) ($outcome['seat'] ?? $seatNo),
                    'score' => (int) ($outcome['score'] ?? $score),
                ],
                'bottom'         => $outcome['remain'],
                'bottomRevealed' => true,
                'myCards'        => $cards,
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

        return [
            'code'       => 0,
            'message'    => 'ok',
            'data'       => $selfData,
            '_broadcast' => ['perFd' => $perFd],
        ];
    }
}
