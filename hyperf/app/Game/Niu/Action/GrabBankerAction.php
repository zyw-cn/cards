<?php

declare(strict_types=1);

namespace App\Game\Niu\Action;

use App\Exception\ApiException;
use App\Facades\Log;
use App\Game\Contract\GameActionInterface;
use App\Game\Contract\GameContext;
use App\Game\Niu\CallBack;
use App\Game\Niu\NiuConsts;
use App\Game\Niu\RoundPhase;
use App\Game\Platform\Timeout\RoomTimeoutService;
use App\Game\Support\GameRecordHooks;
use App\Model\Game\GameRound;
use App\Model\Game\NiuGrab;
use App\Service\Game\GameRoundService;
use App\Service\Game\NiuGrabService;
use App\Service\Game\RoomMemberService;
use App\Service\Game\RoomService;
use App\Utils\WebSocketDebug;
use App\Utils\WebSocketSession;
use Hyperf\DbConnection\Db;
use function Hyperf\Support\make;

/**
 * Niu.Grab / Niu.GrabBanker — 抢庄
 *
 * - bankerMode=free：发完牌后全员同时可抢，不按座位轮候
 * - 其他模式：按座位顺序，current_player_id 轮转
 *
 * body.multiple：0=不抢，1/2/3/5=抢庄倍数（也兼容 body.score）
 * body._auto：超时/系统代操作标记，不清除托管
 *
 * 全员提交后定庄：
 *   - 倍数最高者当庄；并列随机
 *   - 全员不抢 → 系统随机明庄（source=2，倍数至少 1）
 * 定庄后：round.master_id / multiple 落库，阶段切到 show（前端再进下注或亮牌）
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
        // 兼容前端用 score 字段传倍数
        $multiple = (int) ($body['multiple'] ?? $body['score'] ?? 0);
        if (! in_array($multiple, NiuConsts::GRAB_MULTIPLES, true)) {
            return ['code' => 400, 'message' => '抢庄倍数不合法', 'data' => null];
        }
        if ($playerId <= 0) {
            return ['code' => 401, 'message' => '请先登录', 'data' => null];
        }

        $roomMemberService = make(RoomMemberService::class);
        $gameRoundService = make(GameRoundService::class);
        $grabService = make(NiuGrabService::class);
        $roomService = make(RoomService::class);

        $member = $roomMemberService->getActiveByPlayer($playerId);
        if ($member === null) {
            return ['code' => 400, 'message' => '您还未加入任何房间', 'data' => null];
        }
        $roomId = (int) $member->room_id;
        $seatNo = (int) ($member->seat_no ?? 0);
        $room = $roomService->getOne(['id' => $roomId], 'id,rule');
        $isFree = $roomService->resolveNiuBankerMode($room->rule ?? null) === 'free';

        // ============================================================
        // 事务内：落库抢庄；若全员到位则定庄并切到下注
        // ============================================================
        try {
            $outcome = Db::transaction(function () use (
                $roomId,
                $playerId,
                $seatNo,
                $multiple,
                $body,
                $gameRoundService,
                $grabService,
                $roomMemberService,
                $isFree
            ) {
                // 锁住本局，串行化并发抢庄
                $round = $gameRoundService->lockForUpdate(
                    ['room_id' => $roomId, 'status' => GameRound::STATUS_PLAYING],
                    ['id', 'master_id', 'status', 'extra', 'multiple']
                );
                if ($round === null) {
                    throw new ApiException('本局尚未开始');
                }
                if ((int) $round->master_id !== 0) {
                    throw new ApiException('庄家已产生');
                }
                $phaseState = RoundPhase::decode($round->extra);
                if ($phaseState['phase'] !== NiuConsts::PHASE_GRAB) {
                    throw new ApiException('当前不是抢庄阶段');
                }
                // 自由抢庄：不校验轮次；轮流模式才要求 current_player_id
                $currentPid = (int) ($phaseState['current_player_id'] ?? 0);
                if (! $isFree && $currentPid > 0 && $currentPid !== $playerId) {
                    throw new ApiException('还未轮到您抢庄');
                }
                $roundId = (int) $round->id;

                if ($grabService->hasGrabbed($roundId, $playerId)) {
                    throw new ApiException('您已完成抢庄');
                }

                // 每人每局一条；uk_round_member 防重复
                $grabService->save([
                    'game_round_id' => $roundId,
                    'seat_no'       => $seatNo,
                    'member_id'     => $playerId,
                    'multiple'      => $multiple,
                    'source'        => NiuGrab::SOURCE_PLAYER,
                    'is_auto'       => ! empty($body['_auto']) ? 1 : 0,
                    'create_time'   => date('Y-m-d H:i:s'),
                ]);

                $members = $roomMemberService->getActiveInRoom($roomId, 'player_id,seat_no,fd');
                usort($members, static fn (array $a, array $b): int => (int) $a['seat_no'] <=> (int) $b['seat_no']);
                $need = count($members);
                $grabs = $grabService->loadByRound($roundId);

                // 尚有人未抢 → 只广播进度
                if (count($grabs) < $need) {
                    $grabbedIds = array_fill_keys(array_map(
                        static fn (array $grab): int => (int) $grab['player_id'],
                        $grabs
                    ), true);
                    $nextPid = 0;
                    $nextSeat = -1;
                    if (! $isFree) {
                        $next = null;
                        foreach ($members as $candidate) {
                            if (! isset($grabbedIds[(int) $candidate['player_id']])) {
                                $next = $candidate;
                                break;
                            }
                        }
                        if ($next === null) {
                            throw new ApiException('无法确定下一位抢庄玩家');
                        }
                        $nextPid = (int) $next['player_id'];
                        $nextSeat = (int) $next['seat_no'];
                    }
                    $gameRoundService->update($roundId, [
                        'extra' => RoundPhase::encode(
                            NiuConsts::PHASE_GRAB,
                            NiuConsts::CARDS_PER_PLAYER,
                            $isFree ? null : $nextPid,
                            $isFree ? null : $nextSeat
                        ),
                    ]);

                    return [
                        'type'            => 'continue',
                        'round_id'        => $roundId,
                        'seat'            => $seatNo,
                        'multiple'        => $multiple,
                        'grabbed'         => count($grabs),
                        'need'            => $need,
                        'grabs'           => $grabs,
                        'members'         => $members,
                        'currentPlayerId' => $isFree ? 0 : $nextPid,
                        'currentSeat'     => $isFree ? -1 : $nextSeat,
                        'bankerMode'      => $isFree ? 'free' : 'turn',
                    ];
                }

                // 全员到位 → 定庄
                $decided = $grabService->decideBanker($grabs);
                if ($decided === null) {
                    throw new ApiException('定庄失败');
                }

                // 明庄：回写该座位的 multiple/source，便于回放对账
                if (! empty($decided['is_system'])) {
                    $grabService->update(
                        [
                            'game_round_id' => $roundId,
                            'member_id'     => $decided['player_id'],
                        ],
                        [
                            'multiple' => $decided['multiple'],
                            'source'   => NiuGrab::SOURCE_SYSTEM,
                        ]
                    );
                }

                // master_id=庄家；multiple=本局叫分倍数（结算用）；直接进入明牌
                $gameRoundService->update($roundId, [
                    'master_id' => $decided['player_id'],
                    'multiple'  => $decided['multiple'],
                    'extra'     => RoundPhase::encode(NiuConsts::PHASE_SHOW, NiuConsts::CARDS_PER_PLAYER),
                ]);

                return [
                    'type'         => 'banker',
                    'round_id'     => $roundId,
                    'seat'         => $seatNo,
                    'multiple'     => $multiple,
                    'bankerSeat'   => $decided['seat'],
                    'bankerPid'    => $decided['player_id'],
                    'grabMultiple' => $decided['multiple'],
                    'isSystem'     => (bool) $decided['is_system'],
                    'needRandom'   => ! empty($decided['need_random']),
                    'candidates'   => $decided['candidates'] ?? [],
                    'grabs'        => $grabService->loadByRound($roundId),
                    'members'      => $members,
                ];
            });
        } catch (ApiException $e) {
            WebSocketDebug::log('niu_grab_reject', [
                'uid'      => $playerId,
                'seat'     => $seatNo,
                'multiple' => $multiple,
                'message'  => $e->getMessage(),
            ]);

            return ['code' => 400, 'message' => $e->getMessage(), 'data' => null];
        } catch (\Throwable $e) {
            Log::error('斗牛抢庄失败', [
                'room_id'   => $roomId,
                'player_id' => $playerId,
                'error'     => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);

            return ['code' => 500, 'message' => '抢庄失败，请重试', 'data' => null];
        }

        // ============================================================
        // 事务外：清托管、挂超时、广播进度或定庄结果
        // ============================================================
        // 真人手动操作 → 取消托管（超时代打带 _auto，不在此清除）
        if (empty($body['_auto'])) {
            $roomMemberService->clearTrust($roomId, $playerId);
        }
        // 自由抢庄共用一段倒计时：中途有人叫分不重挂，避免没叫的人被续时
        $keepSharedGrab = $isFree && ($outcome['type'] ?? '') === 'continue';
        if (! $keepSharedGrab) {
            make(RoomTimeoutService::class)->armGameTimeout($roomId);
        }

        GameRecordHooks::append(
            (int) ($outcome['round_id'] ?? 0),
            'grab',
            $seatNo,
            -1,
            [],
            ['multiple' => (int) ($outcome['multiple'] ?? $multiple)]
        );

        $members = $outcome['members'];
        $publicGrabs = array_map(static fn (array $g): array => [
            'seat'     => $g['seat'],
            'playerId' => $g['player_id'],
            'multiple' => $g['multiple'],
            'source'   => $g['source'] ?? NiuGrab::SOURCE_PLAYER,
        ], $outcome['grabs']);

        // 抢庄进行中
        if ($outcome['type'] === 'continue') {
            $data = [
                'callback' => CallBack::GRAB,
                'phase'    => NiuConsts::PHASE_GRAB,
                'lastGrab' => ['index' => $outcome['seat'], 'multiple' => $outcome['multiple']],
                'grabbed'  => $outcome['grabbed'],
                'need'     => $outcome['need'],
                'grabs'    => $publicGrabs,
                'currentPlayerId' => $outcome['currentPlayerId'],
                'currentIndex'    => $outcome['currentSeat'],
                'bankerMode'      => $outcome['bankerMode'] ?? '',
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

        // 庄家已产生：通知进入明牌阶段
        $publicCandidates = array_map(static fn (array $c): array => [
            'seat'     => (int) $c['seat'],
            'playerId' => (int) $c['player_id'],
            'multiple' => (int) ($c['multiple'] ?? 0),
        ], $outcome['candidates'] ?? []);

        $data = [
            'callback'       => CallBack::GRAB,
            'phase'          => NiuConsts::PHASE_SHOW,
            'lastGrab'       => ['index' => $outcome['seat'], 'multiple' => $outcome['multiple']],
            'bankerIndex'    => $outcome['bankerSeat'],
            'bankerPlayerId' => $outcome['bankerPid'],
            'grabMultiple'   => $outcome['grabMultiple'],
            'isSystemBanker' => $outcome['isSystem'],
            'needRandom'     => ! empty($outcome['needRandom']),
            'candidates'     => $publicCandidates,
            'grabs'          => $publicGrabs,
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
}
