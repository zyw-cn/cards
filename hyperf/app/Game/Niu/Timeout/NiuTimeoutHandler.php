<?php

declare(strict_types=1);

namespace App\Game\Niu\Timeout;

use App\Facades\Log;
use App\Game\Contract\GameTimeoutHandlerInterface;
use App\Game\Contract\TimeoutPhase;
use App\Game\Contract\TrustDecision;
use App\Game\Niu\Action\BetAction;
use App\Game\Niu\Action\GrabBankerAction;
use App\Game\Niu\Action\ShowAction;
use App\Game\Niu\Ai\NiuTrustAi;
use App\Game\Niu\NiuConsts;
use App\Game\Niu\RoundPhase;
use App\Game\Platform\RoomWsPusher;
use App\Model\Game\GameRound;
use App\Service\BaseService;
use App\Service\Game\GamePlayCardService;
use App\Service\Game\GameRoundService;
use App\Service\Game\NiuBetService;
use App\Service\Game\NiuGrabService;
use App\Service\Game\RoomMemberService;
use App\Game\Platform\Timeout\RoomTimeoutService;
use Swoole\Timer;
use function Hyperf\Support\make;

/**
 * 斗牛超时 / 托管代打（归属 Niu Module）
 *
 * 托管走 {@see NiuTrustAi} 免决策：不抢、最低注、自动最大牛亮牌。
 */
class NiuTimeoutHandler extends BaseService implements GameTimeoutHandlerInterface
{
    /** 托管后代打间隔（秒） */
    private const TRUSTED_PLAY_TIMEOUT = 2;

    public function __construct(
        protected RoomMemberService $roomMemberService,
        protected RoomWsPusher $pusher,
        protected NiuGrabService $grabService,
        protected NiuBetService $betService,
    ) {
    }

    /**
     * 解析局内阶段；无进行中局则返回 null，内核挂 wait 倒计时
     */
    public function resolveInGamePhase(int $roomId, int $requiredPlayers = 0): ?TimeoutPhase
    {
        $round = make(GameRoundService::class)->getOne(
            [
                'room_id' => $roomId,
                'status'  => GameRound::STATUS_PLAYING,
            ],
            'id,master_id,status,extra'
        );
        if ($round === null) {
            return null;
        }

        $phase = RoundPhase::phaseOf($round->extra);

        $phaseState = RoundPhase::decode($round->extra);
        if ($phase === NiuConsts::PHASE_GRAB) {
            $expectPid = (int) ($phaseState['current_player_id'] ?? 0);
            $seconds = NiuConsts::GRAB_TIMEOUT;
            if ($expectPid > 0 && $this->roomMemberService->isTrusted($roomId, $expectPid)) {
                $seconds = self::TRUSTED_PLAY_TIMEOUT;
            }

            return new TimeoutPhase(NiuConsts::PHASE_GRAB, $seconds, $expectPid);
        }

        return match ($phase) {
            NiuConsts::PHASE_BET  => new TimeoutPhase(NiuConsts::PHASE_BET, NiuConsts::BET_TIMEOUT, 0),
            NiuConsts::PHASE_SHOW => new TimeoutPhase(NiuConsts::PHASE_SHOW, NiuConsts::SHOW_TIMEOUT, 0),
            default               => null,
        };
    }

    public function actorStillMatches(int $roomId, string $phase, int $expectPlayerId): bool
    {
        $inGame = $this->resolveInGamePhase($roomId);
        if ($inGame === null || $inGame->name !== $phase) {
            return false;
        }

        return $expectPlayerId <= 0 || $inGame->expectPlayerId === $expectPlayerId;
    }

    public function onTimeout(int $roomId, string $phase, int $requiredPlayers): bool
    {
        return match ($phase) {
            NiuConsts::PHASE_GRAB => $this->onGrabTimeout($roomId),
            NiuConsts::PHASE_BET  => $this->onBetTimeout($roomId),
            NiuConsts::PHASE_SHOW => $this->onShowTimeout($roomId),
            default               => false,
        };
    }

    public function tryImmediateAutoPlay(int $roomId, int $playerId): void
    {
        if ($roomId <= 0 || $playerId <= 0) {
            return;
        }
        if (! $this->roomMemberService->isTrusted($roomId, $playerId)) {
            return;
        }

        // 抢庄轮次可走房间超时 2s；下注/亮牌为并行阶段，单独延时 2s 后代打
        $phase = null;
        $round = make(GameRoundService::class)->getOne(
            ['room_id' => $roomId, 'status' => GameRound::STATUS_PLAYING],
            'id,extra'
        );
        if ($round !== null) {
            $phase = RoundPhase::phaseOf($round->extra);
        }
        if ($phase === NiuConsts::PHASE_GRAB) {
            make(RoomTimeoutService::class)->armGameTimeout($roomId);

            return;
        }

        Timer::after(self::TRUSTED_PLAY_TIMEOUT * 1000, function () use ($roomId, $playerId) {
            $this->runTrustedAutoPlay($roomId, $playerId);
        });
    }

    private function runTrustedAutoPlay(int $roomId, int $playerId): void
    {
        if (! $this->roomMemberService->isTrusted($roomId, $playerId)) {
            return;
        }

        try {
            $round = make(GameRoundService::class)->getOne(
                ['room_id' => $roomId, 'status' => GameRound::STATUS_PLAYING],
                'id,extra,master_id'
            );
            if ($round === null) {
                return;
            }
            $phase = RoundPhase::phaseOf($round->extra);
            $roundId = (int) $round->id;
            $members = $this->roomMemberService->getActiveInRoom($roomId, 'player_id,fd');
            $fd = 0;
            foreach ($members as $m) {
                if ((int) ($m['player_id'] ?? 0) === $playerId) {
                    $fd = (int) ($m['fd'] ?? 0);
                    break;
                }
            }

            if ($phase === NiuConsts::PHASE_GRAB) {
                if ($this->grabService->hasGrabbed($roundId, $playerId)) {
                    return;
                }
                $phaseState = RoundPhase::decode($round->extra);
                $currentPid = (int) ($phaseState['current_player_id'] ?? 0);
                $room = make(\App\Service\Game\RoomService::class)->getOne(['id' => $roomId], 'id,rule');
                $isFree = make(\App\Service\Game\RoomService::class)->resolveNiuBankerMode($room->rule ?? null) === 'free';
                if (! $isFree && $currentPid > 0 && $currentPid !== $playerId) {
                    return;
                }
                $decision = make(NiuTrustAi::class)->decide('grab');
                $result = make(GrabBankerAction::class)->run([
                    'uid'      => $playerId,
                    'fd'       => $fd,
                    'multiple' => $decision->multiple(),
                    '_auto'    => true,
                ]);
                if ((int) ($result['code'] ?? -1) === 0) {
                    $this->pushActionResult($roomId, $result);
                }

                return;
            }

            if ($phase === NiuConsts::PHASE_BET) {
                $bankerPid = (int) ($round->master_id ?? 0);
                if ($playerId === $bankerPid) {
                    return;
                }
                foreach ($this->betService->loadByRound($roundId) as $b) {
                    if ((int) ($b['player_id'] ?? 0) === $playerId) {
                        return;
                    }
                }
                $decision = make(NiuTrustAi::class)->decide('bet');
                $result = make(BetAction::class)->run([
                    'uid'      => $playerId,
                    'fd'       => $fd,
                    'multiple' => $decision->multiple(),
                    '_auto'    => true,
                ]);
                if ((int) ($result['code'] ?? -1) === 0) {
                    $this->pushActionResult($roomId, $result);
                }

                return;
            }

            if ($phase === NiuConsts::PHASE_SHOW) {
                if (in_array($playerId, RoundPhase::shownIdsOf($round->extra), true)) {
                    return;
                }
                $hand = make(GamePlayCardService::class)->getLatestHand($roundId, $playerId);
                $decision = make(NiuTrustAi::class)->decide('show', ['hand' => $hand]);
                if ($decision->action !== TrustDecision::ACTION_SHOW) {
                    return;
                }
                $result = make(ShowAction::class)->run([
                    'uid'   => $playerId,
                    'fd'    => $fd,
                    '_auto' => true,
                ]);
                if ((int) ($result['code'] ?? -1) === 0) {
                    $this->pushActionResult($roomId, $result);
                }
            }
        } catch (\Throwable $e) {
            Log::error('斗牛托管代打失败', [
                'room_id'   => $roomId,
                'player_id' => $playerId,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * 从未真正打完过一局（无 master_id>0 且已结束的局）
     */
    public function wasNeverPlayed(int $roomId): bool
    {
        $rounds = make(GameRoundService::class)->getAll(
            [
                'room_id' => $roomId,
                ['master_id', '>', 0],
                'status'  => GameRound::STATUS_ENDED,
            ],
            'id'
        );

        return empty($rounds);
    }

    // ============================================================
    // 各阶段超时
    // ============================================================

    /** 抢庄超时：自由模式代所有未抢的人不抢；轮流模式只代当前人 */
    private function onGrabTimeout(int $roomId): bool
    {
        $round = make(GameRoundService::class)->getOne(
            ['room_id' => $roomId, 'status' => GameRound::STATUS_PLAYING],
            'id,extra,master_id'
        );
        if ($round === null || RoundPhase::phaseOf($round->extra) !== NiuConsts::PHASE_GRAB) {
            return false;
        }
        $roundId = (int) $round->id;
        $phaseState = RoundPhase::decode($round->extra);
        $currentPid = (int) ($phaseState['current_player_id'] ?? 0);
        $room = make(\App\Service\Game\RoomService::class)->getOne(['id' => $roomId], 'id,rule');
        $isFree = make(\App\Service\Game\RoomService::class)->resolveNiuBankerMode($room->rule ?? null) === 'free';

        $members = $this->roomMemberService->getActiveInRoom($roomId, 'player_id,fd');
        $pushed = false;

        if ($isFree || $currentPid <= 0) {
            // 自由抢庄：超时一次性代所有未抢玩家不抢
            foreach ($members as $m) {
                $pid = (int) ($m['player_id'] ?? 0);
                if ($pid <= 0 || $this->grabService->hasGrabbed($roundId, $pid)) {
                    continue;
                }
                $grabMultiple = make(NiuTrustAi::class)->decide('grab')->multiple();
                $last = make(GrabBankerAction::class)->run([
                    'uid'      => $pid,
                    'fd'       => (int) ($m['fd'] ?? 0),
                    'multiple' => $grabMultiple,
                    '_auto'    => true,
                ]);
                if ((int) ($last['code'] ?? -1) === 0) {
                    $this->pushActionResult($roomId, $last);
                    $pushed = true;
                    continue;
                }
                $msg = (string) ($last['message'] ?? '');
                if (str_contains($msg, '已完成抢庄') || str_contains($msg, '已抢庄')) {
                    $pushed = true;
                }
            }

            return $pushed;
        }

        foreach ($members as $m) {
            $pid = (int) ($m['player_id'] ?? 0);
            if ($pid !== $currentPid) {
                continue;
            }
            $grabMultiple = make(NiuTrustAi::class)->decide('grab')->multiple();
            $last = make(GrabBankerAction::class)->run([
                'uid'      => $pid,
                'fd'       => (int) ($m['fd'] ?? 0),
                'multiple' => $grabMultiple,
                '_auto'    => true,
            ]);
            if ((int) ($last['code'] ?? -1) !== 0) {
                $msg = (string) ($last['message'] ?? '');
                if (str_contains($msg, '已完成抢庄') || str_contains($msg, '已抢庄')) {
                    return true;
                }

                return false;
            }
            $this->pushActionResult($roomId, $last);

            return true;
        }

        return false;
    }

    /** 下注超时：给未下注闲家代「最小倍数」 */
    private function onBetTimeout(int $roomId): bool
    {
        $round = make(GameRoundService::class)->getOne(
            ['room_id' => $roomId, 'status' => GameRound::STATUS_PLAYING],
            'id,extra,master_id'
        );
        if ($round === null || RoundPhase::phaseOf($round->extra) !== NiuConsts::PHASE_BET) {
            return false;
        }
        $roundId = (int) $round->id;
        $bankerPid = (int) ($round->master_id ?? 0);
        $members = $this->roomMemberService->getActiveInRoom($roomId, 'player_id,fd');
        $done = [];
        foreach ($this->betService->loadByRound($roundId) as $b) {
            $done[(int) $b['player_id']] = true;
        }

        $minBet = make(NiuTrustAi::class)->decide('bet')->multiple();
        $last = null;
        foreach ($members as $m) {
            $pid = (int) ($m['player_id'] ?? 0);
            if ($pid <= 0 || $pid === $bankerPid || isset($done[$pid])) {
                continue;
            }
            $result = make(BetAction::class)->run([
                'uid'      => $pid,
                'fd'       => (int) ($m['fd'] ?? 0),
                'multiple' => $minBet,
                '_auto'    => true,
            ]);
            if ((int) ($result['code'] ?? -1) === 0) {
                $last = $result;
            }
        }
        if ($last === null) {
            return false;
        }
        $this->pushActionResult($roomId, $last);

        return true;
    }

    /** 亮牌超时：一次性自动亮完所有尚未开牌者；全员亮完后结算 */
    private function onShowTimeout(int $roomId): bool
    {
        $round = make(GameRoundService::class)->getOne(
            ['room_id' => $roomId, 'status' => GameRound::STATUS_PLAYING],
            'id,extra'
        );
        if ($round === null || RoundPhase::phaseOf($round->extra) !== NiuConsts::PHASE_SHOW) {
            return false;
        }
        $shown = array_fill_keys(RoundPhase::shownIdsOf($round->extra), true);
        $members = $this->roomMemberService->getActiveInRoom($roomId, 'player_id,fd');
        $pushed = false;
        foreach ($members as $m) {
            $pid = (int) ($m['player_id'] ?? 0);
            if ($pid <= 0 || isset($shown[$pid])) {
                continue;
            }
            $result = make(ShowAction::class)->run([
                'uid'   => $pid,
                'fd'    => (int) ($m['fd'] ?? 0),
                '_auto' => true,
            ]);
            if ((int) ($result['code'] ?? -1) !== 0) {
                Log::warning('斗牛亮牌超时自动开牌失败', [
                    'room_id'   => $roomId,
                    'player_id' => $pid,
                    'message'   => $result['message'] ?? '',
                ]);
                continue;
            }
            // 仍逐个推送牌面；ShowAction 部分亮牌不再重挂超时，故不会再续 10s
            $this->pushActionResult($roomId, $result);
            $pushed = true;
            $shown[$pid] = true;
            if (($result['data']['phase'] ?? '') === 'settle') {
                break;
            }
        }

        return $pushed;
    }

    /**
     * 把 Action 的 _broadcast / data 推给房间成员
     * - perFd：按连接定制（发牌场景）
     * - 否则全员同一份 payload（超时场景含操作者本人）
     *
     * @param array{code?:int, data?:mixed, _broadcast?:array} $result
     */
    private function pushActionResult(int $roomId, array $result): void
    {
        $broadcast = $result['_broadcast'] ?? null;
        if (is_array($broadcast) && ! empty($broadcast['perFd'])) {
            $this->pusher->pushPayloadToFds($broadcast['perFd']);

            return;
        }
        $data = $result['data'] ?? null;
        if (! is_array($data)) {
            return;
        }
        if (is_array($broadcast) && ! empty($broadcast['payload']['data']) && is_array($broadcast['payload']['data'])) {
            $members = $this->roomMemberService->getActiveInRoom($roomId, 'player_id,fd');
            $this->pusher->pushDataToMembers($members, $broadcast['payload']['data']);

            return;
        }
        $members = $this->roomMemberService->getActiveInRoom($roomId, 'player_id,fd');
        $this->pusher->pushDataToMembers($members, $data);
    }
}
