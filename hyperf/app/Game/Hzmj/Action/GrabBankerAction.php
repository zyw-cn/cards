<?php

declare(strict_types=1);

namespace App\Game\Hzmj\Action;

use App\Exception\ApiException;
use App\Facades\Log;
use App\Game\Contract\GameActionInterface;
use App\Game\Contract\GameContext;
use App\Game\Hzmj\CallBack;
use App\Game\Hzmj\HzmjConsts;
use App\Game\Hzmj\RoundState;
use App\Game\Platform\RoomWsPusher;
use App\Game\Platform\Timeout\RoomTimeoutService;
use App\Model\Game\GameRound;
use App\Service\Game\GameRoundService;
use App\Service\Game\NiuGrabService;
use App\Service\Game\RoomMemberService;
use App\Service\Game\RoomService;
use App\Utils\WebSocketSession;
use Hyperf\DbConnection\Db;
use function Hyperf\Support\make;

/**
 * Hzmj.Grab / Hzmj.GrabBanker — 自由抢庄
 *
 * body.multiple：0=不抢，1/2/3=抢庄倍数（兼容 body.score）
 * body._auto：超时系统代「不抢」
 *
 * 全员提交后定庄：倍数最高当庄；并列随机；全员不抢 → 随机明庄（倍数至少 1）
 * 定庄后发牌（无骰子）并推送 startgame
 */
final class GrabBankerAction implements GameActionInterface
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
        $multiple = (int) ($body['multiple'] ?? $body['score'] ?? 0);
        if (! in_array($multiple, HzmjConsts::GRAB_MULTIPLES, true)) {
            return ['code' => 400, 'message' => '抢庄倍数不合法', 'data' => null];
        }
        if ($playerId <= 0) {
            return ['code' => 401, 'message' => '请先登录', 'data' => null];
        }

        $roomMemberService = make(RoomMemberService::class);
        $gameRoundService = make(GameRoundService::class);
        $roomService = make(RoomService::class);

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
                $multiple,
                $body,
                $gameRoundService,
                $roomMemberService,
                $roomService
            ) {
                $round = $gameRoundService->lockForUpdate(
                    ['room_id' => $roomId, 'status' => GameRound::STATUS_PLAYING],
                    ['id', 'master_id', 'status', 'extra', 'multiple', 'player_ids']
                );
                if ($round === null) {
                    throw new ApiException('本局尚未开始');
                }
                if ((int) ($round->master_id ?? 0) > 0) {
                    throw new ApiException('庄家已产生');
                }
                $state = RoundState::decode($round->extra ?? null);
                if (($state['phase'] ?? '') !== HzmjConsts::PHASE_GRAB) {
                    throw new ApiException('当前不是抢庄阶段');
                }

                $grabs = is_array($state['grabs'] ?? null) ? $state['grabs'] : [];
                $pidKey = (string) $playerId;
                if (isset($grabs[$pidKey])) {
                    throw new ApiException('您已完成抢庄');
                }

                $grabs[$pidKey] = [
                    'seat'      => $seatNo,
                    'player_id' => $playerId,
                    'multiple'  => $multiple,
                    'source'    => 1,
                    'is_auto'   => ! empty($body['_auto']) ? 1 : 0,
                ];
                $state['grabs'] = $grabs;

                $members = StartGameAction::enrichMembersPublic(
                    $roomMemberService->getActiveInRoom($roomId)
                );
                $need = RoundState::requiredPlayers(
                    (int) ($roomService->getOne(['id' => $roomId], 'num')?->num ?? count($members))
                );
                usort($members, static fn (array $a, array $b): int => (int) $a['seat_no'] <=> (int) $b['seat_no']);
                $members = array_slice($members, 0, $need);

                $grabList = array_values($grabs);
                if (count($grabList) < $need) {
                    $gameRoundService->update((int) $round->id, [
                        'extra' => RoundState::encode($state),
                    ]);

                    return [
                        'type'     => 'continue',
                        'round_id' => (int) $round->id,
                        'room_num' => (string) ($roomService->getOne(['id' => $roomId], 'room_num')?->room_num ?? ''),
                        'seat'     => $seatNo,
                        'multiple' => $multiple,
                        'grabbed'  => count($grabList),
                        'need'     => $need,
                        'grabs'    => $grabList,
                        'members'  => $members,
                        'luckyNum' => (int) ($state['luckyNum'] ?? 2),
                        'maxPlayers' => $need,
                    ];
                }

                $decided = make(NiuGrabService::class)->decideBanker($grabList, 1);
                if ($decided === null) {
                    throw new ApiException('定庄失败');
                }

                if (! empty($decided['is_system'])) {
                    $bankPid = (string) $decided['player_id'];
                    if (isset($grabs[$bankPid])) {
                        $grabs[$bankPid]['multiple'] = (int) $decided['multiple'];
                        $grabs[$bankPid]['source'] = 2;
                    }
                    $state['grabs'] = $grabs;
                }

                $state['bankBeishu'] = max(1, (int) $decided['multiple']);
                $gameRoundService->update((int) $round->id, [
                    'extra' => RoundState::encode($state),
                ]);
                // 刷新 round 对象上的 extra，供 startDeal 读取 grabs
                $round->extra = RoundState::encode($state);

                $room = $roomService->lockForUpdate(['id' => $roomId], ['id', 'room_num', 'rule', 'num', 'status']);
                if ($room === null) {
                    throw new ApiException('房间不存在');
                }

                $readyIds = array_values(array_filter(array_map(
                    static fn (array $m): int => (int) ($m['player_id'] ?? 0),
                    $members
                )));

                $deal = StartGameAction::startDeal(
                    $room,
                    $round,
                    $readyIds,
                    $members,
                    $need,
                    $gameRoundService,
                    (int) $decided['seat'],
                    max(1, (int) $decided['multiple'])
                );
                $deal['rule'] = RoundState::rulePayloadFromRule($room->rule ?? null)['rule'];
                $deal['ruleObj'] = RoundState::rulePayloadFromRule($room->rule ?? null)['ruleObj'];
                $deal['grabs'] = array_values($grabs);
                $deal['isSystemBanker'] = ! empty($decided['is_system']);
                $deal['needRandom'] = ! empty($decided['need_random']);
                $deal['candidates'] = $decided['candidates'] ?? [];
                $deal['grabMultiple'] = max(1, (int) $decided['multiple']);

                return array_merge($deal, ['type' => 'banker']);
            });
        } catch (ApiException $e) {
            return ['code' => 400, 'message' => $e->getMessage(), 'data' => null];
        } catch (\Throwable $e) {
            Log::error('红中麻将抢庄失败', [
                'room_id'   => $roomId,
                'player_id' => $playerId,
                'error'     => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);

            return ['code' => 500, 'message' => '抢庄失败，请重试', 'data' => null];
        }

        if (empty($body['_auto'])) {
            $roomMemberService->clearTrust($roomId, $playerId);
        }
        if ($fd > 0) {
            $roomMemberService->touchMemberFd((int) $member->id, $fd);
        }

        make(RoomTimeoutService::class)->armGameTimeout($roomId);

        if (($outcome['type'] ?? '') === 'continue') {
            $result = self::buildProgressBroadcast($outcome, $fd, $roomId);
            // 主推送：RoomWsPusher + Sender（跨 worker）；不依赖 WS 入口的 targetFds
            $liveMembers = $roomMemberService->getActiveInRoom($roomId, 'fd,player_id,seat_no');
            make(RoomWsPusher::class)->pushDataToMembers(
                $liveMembers,
                is_array($result['data'] ?? null) ? $result['data'] : [],
                $fd
            );
            Log::info('hzmj grab progress pushed', [
                'room_id'  => $roomId,
                'seat'     => (int) ($outcome['seat'] ?? -1),
                'multiple' => (int) ($outcome['multiple'] ?? -1),
                'self_fd'  => $fd,
                'members'  => count($liveMembers),
                'target_fds' => $result['_broadcast']['targetFds'] ?? [],
            ]);
            // 保留 _broadcast 作兜底（与 RoomWsPusher 可能各推一次，前端 applyGrabs 幂等）

            return $result;
        }

        return StartGameAction::buildDealBroadcastPublic($outcome, $playerId);
    }

    /**
     * @param array<string, mixed> $outcome
     * @return array{code: int, message: string, data: mixed, _broadcast?: array}
     */
    private static function buildProgressBroadcast(array $outcome, int $selfFd, int $roomId = 0): array
    {
        $publicGrabs = [];
        foreach ($outcome['grabs'] as $g) {
            $publicGrabs[] = [
                'seat'      => (int) ($g['seat'] ?? 0),
                'seatNo'    => (int) ($g['seat'] ?? 0),
                'index'     => (int) ($g['seat'] ?? 0),
                'player_id' => (int) ($g['player_id'] ?? 0),
                'playerId'  => (int) ($g['player_id'] ?? 0),
                'multiple'  => (int) ($g['multiple'] ?? 0),
            ];
        }
        $publicSeats = [];
        foreach ($outcome['members'] as $p) {
            $publicSeats[] = [
                'seat_no'    => (int) ($p['seat_no'] ?? 0),
                'seatNo'     => (int) ($p['seat_no'] ?? 0),
                'player_id'  => (int) ($p['player_id'] ?? 0),
                'playerId'   => (int) ($p['player_id'] ?? 0),
                'nickname'   => (string) ($p['nickname'] ?? ''),
                'avatar'     => (string) ($p['avatar'] ?? ''),
                'avatar_url' => (string) ($p['avatar_url'] ?? ''),
                'points'     => (float) ($p['points'] ?? 0),
            ];
        }

        $data = [
            'callback'      => CallBack::GRAB,
            'roomId'        => $outcome['room_num'],
            'roundId'       => $outcome['round_id'],
            'phase'         => HzmjConsts::PHASE_GRAB,
            'seat'          => (int) $outcome['seat'],
            'multiple'      => (int) $outcome['multiple'],
            'lastGrab'      => [
                'index'    => (int) $outcome['seat'],
                'seat'     => (int) $outcome['seat'],
                'seatNo'   => (int) $outcome['seat'],
                'multiple' => (int) $outcome['multiple'],
            ],
            'grabbed'       => (int) $outcome['grabbed'],
            'need'          => (int) $outcome['need'],
            'grabs'         => $publicGrabs,
            'players'       => $publicSeats,
            'seats'         => $publicSeats,
            'luckyNum'      => (int) ($outcome['luckyNum'] ?? 2),
            'maxPlayers'    => (int) ($outcome['maxPlayers'] ?? HzmjConsts::MAX_PLAYERS),
            'grabMultiples' => HzmjConsts::GRAB_MULTIPLES,
            'countdown'     => HzmjConsts::GRAB_TIMEOUT,
        ];

        $roomMemberService = make(RoomMemberService::class);
        $otherFds = $roomId > 0
            ? array_values(array_filter(
                $roomMemberService->getFdsInRoom($roomId),
                static fn (int $x): bool => $x > 0 && $x !== $selfFd
            ))
            : $roomMemberService->otherFds($outcome['members'], $selfFd);

        return [
            'code'       => 0,
            'message'    => 'ok',
            'data'       => $data,
            '_broadcast' => [
                'targetFds' => $otherFds,
                'payload'   => ['code' => 0, 'message' => 'ok', 'data' => $data],
            ],
        ];
    }
}
