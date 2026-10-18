<?php

declare(strict_types=1);

namespace App\Game\Zjh\Timeout;

use App\Facades\Log;
use App\Game\Contract\GameTimeoutHandlerInterface;
use App\Game\Contract\TimeoutPhase;
use App\Game\Contract\TrustDecision;
use App\Game\Platform\RoomWsPusher;
use App\Game\Platform\Timeout\RoomTimeoutService;
use App\Game\Zjh\Action\CallAction;
use App\Game\Zjh\Action\CompareAction;
use App\Game\Zjh\Action\FoldAction;
use App\Game\Zjh\Action\LookAction;
use App\Game\Zjh\Action\RaiseAction;
use App\Game\Zjh\Ai\ZajinhuaAi;
use App\Game\Zjh\CallBack;
use App\Game\Zjh\RoundState;
use App\Game\Zjh\ZjhConsts;
use App\Model\Game\GameRound;
use App\Service\BaseService;
use App\Service\Game\GamePlayCardService;
use App\Service\Game\GameRoundService;
use App\Service\Game\RoomMemberService;
use App\Service\Game\RoomService;
use App\Service\Game\ZjhChipService;
use Hyperf\DbConnection\Db;
use function Hyperf\Support\make;

/**
 * 炸金花超时 / 托管代打：托管走 ZajinhuaAi；非托管超时仍弃牌
 */
class ZjhTimeoutHandler extends BaseService implements GameTimeoutHandlerInterface
{
    /** 托管后代打间隔（秒） */
    private const TRUSTED_PLAY_TIMEOUT = 2;

    public function __construct(
        protected RoomMemberService $roomMemberService,
        protected RoomWsPusher $pusher,
    ) {
    }

    public function resolveInGamePhase(int $roomId, int $requiredPlayers = 0): ?TimeoutPhase
    {
        unset($requiredPlayers);
        $round = make(GameRoundService::class)->getOne(
            ['room_id' => $roomId, 'status' => GameRound::STATUS_PLAYING],
            'id,extra,status'
        );
        if ($round === null) {
            return null;
        }
        $state = RoundState::decode($round->extra);
        if ($state['phase'] !== ZjhConsts::PHASE_BET) {
            return null;
        }
        $turnSeat = (int) $state['turnSeat'];
        $pid = RoundState::playerIdOf($state, $turnSeat);
        if ($pid <= 0 || RoundState::isFolded($state, $turnSeat)) {
            return null;
        }

        $seconds = ZjhConsts::TURN_TIMEOUT;
        if ($this->roomMemberService->isTrusted($roomId, $pid)) {
            $seconds = self::TRUSTED_PLAY_TIMEOUT;
        }

        return new TimeoutPhase(ZjhConsts::PHASE_BET, $seconds, $pid);
    }

    public function actorStillMatches(int $roomId, string $phase, int $expectPlayerId): bool
    {
        $inGame = $this->resolveInGamePhase($roomId);
        if ($inGame === null || $inGame->name !== $phase) {
            return false;
        }
        if ($expectPlayerId <= 0) {
            return true;
        }

        return $inGame->expectPlayerId === $expectPlayerId;
    }

    public function onTimeout(int $roomId, string $phase, int $requiredPlayers): bool
    {
        unset($requiredPlayers);
        if ($phase !== ZjhConsts::PHASE_BET) {
            return false;
        }
        $round = make(GameRoundService::class)->getOne(
            ['room_id' => $roomId, 'status' => GameRound::STATUS_PLAYING],
            'id,extra'
        );
        if ($round === null) {
            return false;
        }
        $state = RoundState::decode($round->extra);
        $seat = (int) $state['turnSeat'];
        $pid = RoundState::playerIdOf($state, $seat);
        if ($pid <= 0) {
            return false;
        }

        if ($this->roomMemberService->isTrusted($roomId, $pid)) {
            return $this->runAiTurn($roomId, $pid, $seat, true);
        }

        return $this->foldAndPush($roomId, $pid, $seat);
    }

    public function tryImmediateAutoPlay(int $roomId, int $playerId): void
    {
        // 托管不即时代打：由 armGameTimeout + TRUSTED_PLAY_TIMEOUT(2s) 驱动
        $phase = $this->resolveInGamePhase($roomId);
        if ($phase === null || $phase->expectPlayerId !== $playerId) {
            return;
        }
        make(RoomTimeoutService::class)->armGameTimeout($roomId);
    }

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

    private function runAiTurn(int $roomId, int $playerId, int $seat, bool $allowLookChain): bool
    {
        try {
            $round = make(GameRoundService::class)->getOne(
                ['room_id' => $roomId, 'status' => GameRound::STATUS_PLAYING],
                'id,extra'
            );
            if ($round === null) {
                return false;
            }
            $state = RoundState::decode($round->extra);
            if ((int) $state['turnSeat'] !== $seat || RoundState::isFolded($state, $seat)) {
                return false;
            }

            $room = make(RoomService::class)->getOne(['id' => $roomId], 'id,rule');
            $caps = ZjhConsts::capsFromRule($room->rule ?? null);
            $hand = make(GamePlayCardService::class)->getLatestHand((int) $round->id, $playerId);
            $balance = make(ZjhChipService::class)->balance($playerId);
            $invested = (string) ($state['invested'][(string) $seat] ?? '0');

            $decision = make(ZajinhuaAi::class)->decide(ZjhConsts::PHASE_BET, [
                'hand'      => $hand,
                'state'     => $state,
                'mySeat'    => $seat,
                'looked'    => RoundState::isLooked($state, $seat),
                'betRound'  => ZajinhuaAi::estimateBetRound($state),
                'balance'   => $balance,
                'invested'  => $invested,
                'caps'      => $caps,
            ]);

            // 看牌不占回合：看完后立刻再决策一手
            if ($decision->action === TrustDecision::ACTION_LOOK && $allowLookChain) {
                $lookResult = make(LookAction::class)->run([
                    'uid'   => $playerId,
                    'fd'    => 0,
                    '_auto' => true,
                ]);
                if ((int) ($lookResult['code'] ?? -1) === 0) {
                    $this->pushActionResult($roomId, $lookResult, $playerId);
                }

                return $this->runAiTurn($roomId, $playerId, $seat, false);
            }

            return $this->executeDecision($roomId, $playerId, $seat, $decision);
        } catch (\Throwable $e) {
            Log::warning('炸金花托管代打失败，回退弃牌', [
                'room_id'   => $roomId,
                'player_id' => $playerId,
                'error'     => $e->getMessage(),
            ]);

            return $this->foldAndPush($roomId, $playerId, $seat);
        }
    }

    private function executeDecision(int $roomId, int $playerId, int $seat, TrustDecision $decision): bool
    {
        $action = $decision->action;
        $payload = $decision->payload;

        if ($action === TrustDecision::ACTION_FOLD) {
            return $this->foldAndPush($roomId, $playerId, $seat);
        }

        if ($action === TrustDecision::ACTION_COMPARE) {
            $result = make(CompareAction::class)->run([
                'uid'        => $playerId,
                'fd'         => 0,
                'targetSeat' => (int) ($payload['targetSeat'] ?? -1),
                '_auto'      => true,
            ]);
            if ((int) ($result['code'] ?? -1) !== 0) {
                return $this->foldAndPush($roomId, $playerId, $seat);
            }
            $this->pushActionResult($roomId, $result, $playerId);
            make(RoomTimeoutService::class)->armGameTimeout($roomId);

            return true;
        }

        if ($action === TrustDecision::ACTION_RAISE) {
            $result = make(RaiseAction::class)->run([
                'uid'    => $playerId,
                'fd'     => 0,
                'amount' => (string) ($payload['amount'] ?? ''),
                '_auto'  => true,
            ]);
            if ((int) ($result['code'] ?? -1) !== 0) {
                // 加注失败改跟注
                $result = make(CallAction::class)->run([
                    'uid'   => $playerId,
                    'fd'    => 0,
                    '_auto' => true,
                ]);
                if ((int) ($result['code'] ?? -1) !== 0) {
                    return $this->foldAndPush($roomId, $playerId, $seat);
                }
            }
            $this->pushActionResult($roomId, $result, $playerId);
            make(RoomTimeoutService::class)->armGameTimeout($roomId);

            return true;
        }

        if ($action === TrustDecision::ACTION_CALL || $action === TrustDecision::ACTION_LOOK) {
            $result = make(CallAction::class)->run([
                'uid'   => $playerId,
                'fd'    => 0,
                '_auto' => true,
            ]);
            if ((int) ($result['code'] ?? -1) !== 0) {
                return $this->foldAndPush($roomId, $playerId, $seat);
            }
            $this->pushActionResult($roomId, $result, $playerId);
            make(RoomTimeoutService::class)->armGameTimeout($roomId);

            return true;
        }

        return $this->foldAndPush($roomId, $playerId, $seat);
    }

    private function foldAndPush(int $roomId, int $pid, int $seat): bool
    {
        try {
            $result = Db::transaction(static function () use ($roomId, $pid, $seat) {
                return FoldAction::act($roomId, $pid, $seat, true);
            });
            $resp = CallAction::respond($result, 0, CallBack::FOLD, $seat);
            $members = $this->roomMemberService->getActiveInRoom($roomId, 'fd,player_id');
            $data = $resp['data'] ?? null;
            if (is_array($data)) {
                $this->pusher->pushDataToMembers($members, $data);
            }
            make(RoomTimeoutService::class)->armGameTimeout($roomId);

            return true;
        } catch (\Throwable $e) {
            Log::warning('炸金花弃牌失败', [
                'room_id' => $roomId,
                'error'   => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @param array<string, mixed> $result Action::run 返回值（含 data / _broadcast）
     */
    private function pushActionResult(int $roomId, array $result, int $ownerPlayerId): void
    {
        $broadcast = is_array($result['_broadcast'] ?? null) ? $result['_broadcast'] : [];
        if (! empty($broadcast['perFd']) && is_array($broadcast['perFd'])) {
            $this->pusher->pushPayloadToFds($broadcast['perFd']);
        }

        $data = is_array($result['data'] ?? null) ? $result['data'] : null;
        if ($data === null) {
            return;
        }

        $members = $this->roomMemberService->getActiveInRoom($roomId, 'fd,player_id');
        $publicPayload = is_array($broadcast['payload']['data'] ?? null)
            ? $broadcast['payload']['data']
            : null;

        // 看牌等：本人带 myCards，其他人只推公共包
        if ($publicPayload !== null && array_key_exists('myCards', $data)) {
            foreach ($members as $m) {
                $pid = (int) ($m['player_id'] ?? 0);
                $fd = (int) ($m['fd'] ?? 0);
                if ($fd <= 0) {
                    continue;
                }
                $pack = $pid === $ownerPlayerId ? $data : $publicPayload;
                $this->pusher->pushDataToMembers([['fd' => $fd, 'player_id' => $pid]], $pack);
            }

            return;
        }

        $this->pusher->pushDataToMembers($members, $data);
    }
}
