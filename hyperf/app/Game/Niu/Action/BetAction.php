<?php

declare(strict_types=1);

namespace App\Game\Niu\Action;

use App\Exception\ApiException;
use App\Facades\Log;
use App\Game\Contract\GameActionInterface;
use App\Game\Contract\GameContext;
use App\Game\Niu\CallBack;
use App\Game\Niu\Deal;
use App\Game\Niu\NiuConsts;
use App\Game\Niu\RoundPhase;
use App\Game\Platform\Start\SharedStartGame;
use App\Game\Platform\Timeout\RoomTimeoutService;
use App\Game\Support\GameRecordHooks;
use App\Model\Game\GameRound;
use App\Service\Game\GamePlayCardService;
use App\Service\Game\GameRoundService;
use App\Service\Game\NiuBetService;
use App\Service\Game\RoomMemberService;
use App\Service\Game\RoomService;
use App\Utils\WebSocketDebug;
use App\Utils\WebSocketSession;
use Hyperf\DbConnection\Db;
use function Hyperf\Support\make;

/**
 * Niu.Bet — 闲家下注
 *
 * body.multiple：下注倍数（相对底分，见 BET_MULTIPLES）
 * 庄家禁止调用；amount = bottom_points × multiple 落库快照（防事后改配置对不上账）
 *
 * 全部闲家下注完成后立刻发 5 张牌：
 *   - 手牌写入 game_play_card（seq=0）
 *   - 阶段切到 show
 *   - 推送用 perFd：每人只拿自己的 myCards（防透视）
 */
final class BetAction implements GameActionInterface
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
        $multiple = (int) ($body['multiple'] ?? 1);
        if (! in_array($multiple, NiuConsts::BET_MULTIPLES, true)) {
            return ['code' => 400, 'message' => '下注倍数不合法', 'data' => null];
        }
        if ($playerId <= 0) {
            return ['code' => 401, 'message' => '请先登录', 'data' => null];
        }

        $roomMemberService = make(RoomMemberService::class);
        $gameRoundService = make(GameRoundService::class);
        $betService = make(NiuBetService::class);
        $gamePlayCardService = make(GamePlayCardService::class);

        $member = $roomMemberService->getActiveByPlayer($playerId);
        if ($member === null) {
            return ['code' => 400, 'message' => '您还未加入任何房间', 'data' => null];
        }
        $roomId = (int) $member->room_id;
        $seatNo = (int) ($member->seat_no ?? 0);

        // ============================================================
        // 事务内：落库下注；若闲家全齐则发牌并切到亮牌
        // ============================================================
        try {
            $outcome = Db::transaction(function () use (
                $roomId,
                $playerId,
                $seatNo,
                $multiple,
                $body,
                $gameRoundService,
                $betService,
                $roomMemberService,
                $gamePlayCardService
            ) {
                $round = $gameRoundService->lockForUpdate(
                    ['room_id' => $roomId, 'status' => GameRound::STATUS_PLAYING],
                    ['id', 'master_id', 'status', 'extra', 'multiple']
                );
                if ($round === null) {
                    throw new ApiException('本局尚未开始');
                }
                $bankerPid = (int) ($round->master_id ?? 0);
                if ($bankerPid <= 0) {
                    throw new ApiException('庄家尚未产生');
                }
                if (RoundPhase::phaseOf($round->extra) !== NiuConsts::PHASE_BET) {
                    throw new ApiException('当前不是下注阶段');
                }
                if ($playerId === $bankerPid) {
                    throw new ApiException('庄家无需下注');
                }
                $roundId = (int) $round->id;

                if ($betService->hasBet($roundId, $playerId)) {
                    throw new ApiException('您已完成下注');
                }

                $roomService = make(RoomService::class);
                $room = $roomService->getOne(['id' => $roomId], 'id,rule,room_num');
                // 下注额快照 = 当前场次底分 × 倍数
                $bottom = $roomService->resolveBottomPoints($room->rule ?? null);
                $amount = $betService->calcAmount($bottom, $multiple);

                $betService->save([
                    'game_round_id' => $roundId,
                    'seat_no'       => $seatNo,
                    'member_id'     => $playerId,
                    'multiple'      => $multiple,
                    'amount'        => $amount,
                    'is_auto'       => ! empty($body['_auto']) ? 1 : 0,
                    'create_time'   => date('Y-m-d H:i:s'),
                ]);

                $members = SharedStartGame::enrichMembers(
                    $roomMemberService->getActiveInRoom($roomId, 'player_id,seat_no,fd,nickname,avatar')
                );
                // 需要下注的人数 = 在座人数 - 庄家
                $idleNeed = 0;
                foreach ($members as $m) {
                    if ((int) ($m['player_id'] ?? 0) !== $bankerPid) {
                        $idleNeed++;
                    }
                }
                $bets = $betService->loadByRound($roundId);

                if (count($bets) < $idleNeed) {
                    return [
                        'type'      => 'continue',
                        'round_id'  => $roundId,
                        'room_num'  => (string) ($room->room_num ?? ''),
                        'seat'      => $seatNo,
                        'multiple'  => $multiple,
                        'amount'    => $amount,
                        'betCount'  => count($bets),
                        'need'      => $idleNeed,
                        'bets'      => $bets,
                        'members'   => $members,
                        'bankerPid' => $bankerPid,
                    ];
                }

                // 全员下注完毕 → 使用开局时已发的牌，切到明牌阶段
                $players = [];
                $missingHand = false;
                foreach ($members as $m) {
                    $seat = (int) $m['seat_no'];
                    $pid = (int) $m['player_id'];
                    $hand = $gamePlayCardService->getLatestHand($roundId, $pid);
                    if (count($hand) !== NiuConsts::CARDS_PER_PLAYER) {
                        $missingHand = true;
                    }
                    sort($hand);
                    $players[] = [
                        'seat_no'    => $seat,
                        'player_id'  => $pid,
                        'nickname'   => (string) ($m['nickname'] ?? ''),
                        'avatar'     => (string) ($m['avatar'] ?? ''),
                        'avatar_url' => (string) ($m['avatar_url'] ?? ''),
                        'fd'         => (int) ($m['fd'] ?? 0),
                        'cards'      => $hand,
                    ];
                }
                // 兼容升级前已进入中的旧牌局：若没有开局手牌，则兜底补发一次
                if ($missingHand) {
                    $seats = array_map(static fn ($m): int => (int) $m['seat_no'], $members);
                    $deal = Deal::dealToSeats($seats);
                    foreach ($players as &$player) {
                        $player['cards'] = $deal['hands'][$player['seat_no']] ?? [];
                        sort($player['cards']);
                    }
                    unset($player);
                    $gamePlayCardService->recordDeal($roundId, $players);
                }
                // 阶段 → 亮牌（此时尚未结算）
                $gameRoundService->update($roundId, [
                    'extra' => RoundPhase::encode(NiuConsts::PHASE_SHOW, NiuConsts::CARDS_PER_PLAYER),
                ]);

                return [
                    'type'         => 'dealt',
                    'round_id'     => $roundId,
                    'room_num'     => (string) ($room->room_num ?? ''),
                    'seat'         => $seatNo,
                    'multiple'     => $multiple,
                    'amount'       => $amount,
                    'bets'         => $bets,
                    'players'      => $players,
                    'members'      => $members,
                    'bankerPid'    => $bankerPid,
                    'grabMultiple' => max(1, (int) ($round->multiple ?? 1)),
                ];
            });
        } catch (ApiException $e) {
            WebSocketDebug::log('niu_bet_reject', [
                'uid'      => $playerId,
                'seat'     => $seatNo,
                'multiple' => $multiple,
                'message'  => $e->getMessage(),
            ]);

            return ['code' => 400, 'message' => $e->getMessage(), 'data' => null];
        } catch (\Throwable $e) {
            Log::error('斗牛下注失败', [
                'room_id'   => $roomId,
                'player_id' => $playerId,
                'error'     => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);

            return ['code' => 500, 'message' => '下注失败，请重试', 'data' => null];
        }

        // ============================================================
        // 事务外：清托管、挂超时、广播进度或按 fd 发牌
        // ============================================================
        if (empty($body['_auto'])) {
            $roomMemberService->clearTrust($roomId, $playerId);
        }
        make(RoomTimeoutService::class)->armGameTimeout($roomId);

        GameRecordHooks::append(
            (int) ($outcome['round_id'] ?? 0),
            'bet',
            $seatNo,
            -1,
            [],
            ['multiple' => (int) ($outcome['multiple'] ?? $multiple)]
        );

        $publicBets = array_map(static fn (array $b): array => [
            'seat'     => $b['seat'],
            'playerId' => $b['player_id'],
            'multiple' => $b['multiple'],
            'amount'   => (float) $b['amount'],
        ], $outcome['bets']);

        if ($outcome['type'] === 'continue') {
            $data = [
                'callback' => CallBack::BET,
                'phase'    => NiuConsts::PHASE_BET,
                'lastBet'  => [
                    'index'    => $outcome['seat'],
                    'multiple' => $outcome['multiple'],
                    'amount'   => (float) $outcome['amount'],
                ],
                'betCount' => $outcome['betCount'],
                'need'     => $outcome['need'],
                'bets'     => $publicBets,
            ];

            return [
                'code'       => 0,
                'message'    => 'ok',
                'data'       => $data,
                '_broadcast' => [
                    'targetFds' => $roomMemberService->otherFds($outcome['members'], $fd),
                    'payload'   => ['code' => 0, 'message' => 'ok', 'data' => $data],
                ],
            ];
        }

        $bankerSeat = -1;
        foreach ($outcome['players'] as $p) {
            if ((int) $p['player_id'] === (int) $outcome['bankerPid']) {
                $bankerSeat = (int) $p['seat_no'];
                break;
            }
        }

        // 本人下发真实手牌（前端默认只亮前 2 张，看牌后再翻完；他人仍只见牌背）
        $publicSeats = [];
        $perFd = [];
        $selfData = null;
        foreach ($outcome['players'] as $p) {
            $pfd = (int) ($p['fd'] ?? 0);
            $pid = (int) $p['player_id'];
            $realCards = is_array($p['cards'] ?? null) ? array_values($p['cards']) : [];
            $publicSeats[] = [
                'seat_no'    => (int) $p['seat_no'],
                'player_id'  => $pid,
                'nickname'   => (string) ($p['nickname'] ?? ''),
                'avatar'     => (string) ($p['avatar'] ?? ''),
                'avatar_url' => (string) ($p['avatar_url'] ?? ''),
                'cardsCount' => count($realCards),
            ];
            $payloadData = [
                'callback'     => CallBack::BET,
                'phase'        => NiuConsts::PHASE_SHOW,
                'roomId'       => $outcome['room_num'],
                'roundId'      => $outcome['round_id'],
                'bankerIndex'  => $bankerSeat,
                'grabMultiple' => $outcome['grabMultiple'],
                'bets'         => $publicBets,
                'lastBet'      => [
                    'index'    => $outcome['seat'],
                    'multiple' => $outcome['multiple'],
                    'amount'   => (float) $outcome['amount'],
                ],
                'myCards'      => $realCards,
                'players'      => [],
            ];
            if ($pfd > 0) {
                $perFd[$pfd] = ['code' => 0, 'message' => 'ok', 'data' => $payloadData];
            }
            if ($pid === $playerId) {
                $selfData = $payloadData;
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
            'data'       => $selfData ?? [
                'callback' => CallBack::BET,
                'phase'    => NiuConsts::PHASE_SHOW,
                'myCards'  => [],
                'players'  => $publicSeats,
                'bets'     => $publicBets,
            ],
            '_broadcast' => ['perFd' => $perFd],
        ];
    }
}
