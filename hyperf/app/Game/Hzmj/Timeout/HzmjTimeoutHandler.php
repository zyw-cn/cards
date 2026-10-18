<?php

declare(strict_types=1);

namespace App\Game\Hzmj\Timeout;

use App\Facades\Log;
use App\Game\Contract\GameTimeoutHandlerInterface;
use App\Game\Contract\TimeoutPhase;
use App\Game\Contract\TrustDecision;
use App\Game\Hzmj\Action\OpAction;
use App\Game\Hzmj\Ai\RedChangMahjongAi;
use App\Game\Hzmj\CallBack;
use App\Game\Hzmj\HzmjConsts;
use App\Game\Hzmj\RoundState;
use App\Game\Platform\RoomWsPusher;
use App\Game\Platform\Timeout\RoomTimeoutService;
use App\Model\Game\GameRound;
use App\Service\BaseService;
use App\Service\Game\GamePlayCardService;
use App\Service\Game\GameRoundService;
use App\Service\Game\RoomMemberService;
use function Hyperf\Support\make;

/**
 * 红中麻将超时：抢庄 15s；出牌/响应 15s；托管代打延时 2s
 * 托管/超时代打：可胡（自摸 / 抢杠胡）一律自动胡，其余碰杠问询默认过
 */
class HzmjTimeoutHandler extends BaseService implements GameTimeoutHandlerInterface
{
    public const PHASE_PLAY = 'play';

    public const PHASE_GRAB = 'grab';

    private const PLAY_TIMEOUT = 15;

    private const GRAB_TIMEOUT = 15;

    /** 托管后代打间隔（秒） */
    private const TRUSTED_PLAY_TIMEOUT = 2;

    public function __construct(
        protected RoomMemberService $roomMemberService,
        protected RoomWsPusher $pusher,
    ) {
    }

    public function resolveInGamePhase(int $roomId, int $requiredPlayers = 0): ?TimeoutPhase
    {
        $round = make(GameRoundService::class)->getOne(
            [
                'room_id' => $roomId,
                'status'  => GameRound::STATUS_PLAYING,
            ],
            'id,master_id,extra'
        );
        if ($round === null) {
            return null;
        }
        $state = RoundState::decode($round->extra ?? null);
        if (($state['phase'] ?? '') === HzmjConsts::PHASE_GRAB && (int) ($round->master_id ?? 0) <= 0) {
            return new TimeoutPhase(self::PHASE_GRAB, self::GRAB_TIMEOUT, 0);
        }

        $turn = $this->resolveTurn($roomId, $requiredPlayers);
        if ($turn === null) {
            return null;
        }

        $seconds = self::PLAY_TIMEOUT;
        if ($this->isTrustedPlayer($roomId, $turn)) {
            $seconds = self::TRUSTED_PLAY_TIMEOUT;
        }

        return new TimeoutPhase(
            self::PHASE_PLAY,
            $seconds,
            (int) ($turn['player_id'] ?? 0),
        );
    }

    public function actorStillMatches(int $roomId, string $phase, int $expectPlayerId): bool
    {
        if ($phase === self::PHASE_GRAB) {
            $inGame = $this->resolveInGamePhase($roomId);
            return $inGame !== null && $inGame->name === self::PHASE_GRAB;
        }
        if ($phase !== self::PHASE_PLAY) {
            return false;
        }
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
        if ($phase === self::PHASE_GRAB) {
            return $this->onGrabTimeout($roomId);
        }
        if ($phase !== self::PHASE_PLAY) {
            return false;
        }

        $turn = $this->resolveTurn($roomId, $requiredPlayers);
        $turnPid = (int) ($turn['player_id'] ?? 0);
        $wasTrusted = $this->isTrustedPlayer($roomId, $turn);

        $pushed = $this->autoOpAndPush($roomId, $turn, $turnPid, $wasTrusted);

        return $pushed;
    }

    /** 抢庄超时：未抢的一律代「不抢」 */
    private function onGrabTimeout(int $roomId): bool
    {
        $round = make(GameRoundService::class)->getOne(
            [
                'room_id' => $roomId,
                'status'  => GameRound::STATUS_PLAYING,
            ],
            'id,master_id,extra'
        );
        if ($round === null || (int) ($round->master_id ?? 0) > 0) {
            return false;
        }
        $state = RoundState::decode($round->extra ?? null);
        if (($state['phase'] ?? '') !== HzmjConsts::PHASE_GRAB) {
            return false;
        }
        $grabs = is_array($state['grabs'] ?? null) ? $state['grabs'] : [];
        $members = $this->roomMemberService->getActiveInRoom($roomId, 'player_id,fd,seat_no');
        $pushed = false;
        foreach ($members as $m) {
            $pid = (int) ($m['player_id'] ?? 0);
            if ($pid <= 0 || isset($grabs[(string) $pid])) {
                continue;
            }
            $last = make(\App\Game\Hzmj\Action\GrabBankerAction::class)->run([
                'uid'      => $pid,
                'fd'       => (int) ($m['fd'] ?? 0),
                'multiple' => 0,
                '_auto'    => true,
            ]);
            if ((int) ($last['code'] ?? -1) === 0) {
                $this->pushActionResult($roomId, $last);
                $pushed = true;
                // 定庄发牌后打断后续代抢
                if (($last['data']['phase'] ?? '') === HzmjConsts::PHASE_PLAYING
                    || ($last['data']['callback'] ?? '') === CallBack::START_GAME
                ) {
                    break;
                }
                // 刷新本地 grabs 缓存
                $grabs[(string) $pid] = ['multiple' => 0];
            }
        }

        return $pushed;
    }

    /**
     * @param array{code?:int, data?:mixed, _broadcast?:array} $result
     */
    private function pushActionResult(int $roomId, array $result): void
    {
        $broadcast = $result['_broadcast'] ?? null;
        if (is_array($broadcast) && ! empty($broadcast['perFd'])) {
            $this->pusher->pushPayloadToFds($broadcast['perFd']);

            return;
        }
        if (is_array($broadcast) && ! empty($broadcast['targetFds']) && is_array($broadcast['payload'] ?? null)) {
            $payload = $broadcast['payload'];
            $perFd = [];
            foreach ($broadcast['targetFds'] as $fd) {
                $fd = (int) $fd;
                if ($fd > 0) {
                    $perFd[$fd] = $payload;
                }
            }
            if ($perFd !== []) {
                $this->pusher->pushPayloadToFds($perFd);
            }

            return;
        }
        $data = $result['data'] ?? null;
        if (! is_array($data)) {
            return;
        }
        $members = $this->roomMemberService->getActiveInRoom($roomId, 'player_id,fd');
        $this->pusher->pushDataToMembers($members, $data);
    }

    public function tryImmediateAutoPlay(int $roomId, int $playerId): void
    {
        // 托管不再立即代打：由 armGameTimeout + TRUSTED_PLAY_TIMEOUT(2s) 驱动
        if ($roomId <= 0 || $playerId <= 0) {
            return;
        }
        try {
            $inGame = $this->resolveInGamePhase($roomId);
            if ($inGame === null || $inGame->name !== self::PHASE_PLAY) {
                return;
            }
            $turn = $this->resolveTurn($roomId);
            if ($turn === null || (int) $turn['player_id'] !== $playerId) {
                return;
            }
            make(RoomTimeoutService::class)->armGameTimeout($roomId);
        } catch (\Throwable $e) {
            Log::error('红中麻将托管代打调度失败', [
                'room_id'   => $roomId,
                'player_id' => $playerId,
                'error'     => $e->getMessage(),
            ]);
        }
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

    /**
     * @param array{round_id?:int,seat?:int,player_id?:int,fd?:int,mode?:string,wait_ops?:list<int>}|null $turn
     */
    private function autoOpAndPush(int $roomId, ?array $turn, int $turnPid, bool $wasTrusted): bool
    {
        if ($turn === null) {
            return false;
        }

        $body = $this->buildAutoBody($turn);
        if ($body === null) {
            return false;
        }

        $result = make(OpAction::class)->run($body);
        if (($result['code'] ?? 1) !== 0) {
            Log::warning('红中麻将超时代打失败', [
                'room_id' => $roomId,
                'pid'     => $turnPid,
                'body'    => $body,
                'message' => $result['message'] ?? '',
            ]);

            return false;
        }

        $data = is_array($result['data'] ?? null) ? $result['data'] : null;
        if ($data === null) {
            return false;
        }

        // 已托管代打：只给操作者带 trusted，避免全桌误进托管藏表/快表
        if ($wasTrusted) {
            $data['autoHosted'] = true;
            $data['hostedIndex'] = (int) ($turn['seat'] ?? -1);
        } elseif ($turnPid > 0 && empty($body['_skipHost'])) {
            if ($this->roomMemberService->registerPlayTimeout($roomId, $turnPid)) {
                $data['autoHosted'] = true;
                $data['hostedIndex'] = (int) ($turn['seat'] ?? -1);
            }
        }

        $broadcast = is_array($result['_broadcast'] ?? null) ? $result['_broadcast'] : [];
        if (! empty($broadcast['perFd']) && is_array($broadcast['perFd'])) {
            $actorFd = (int) ($turn['fd'] ?? 0);
            foreach ($broadcast['perFd'] as $fd => &$payload) {
                if (! is_array($payload['data'] ?? null)) {
                    continue;
                }
                if (! empty($data['autoHosted'])) {
                    $payload['data']['autoHosted'] = true;
                    $payload['data']['hostedIndex'] = $data['hostedIndex'] ?? -1;
                }
                // trusted 仅回写给本人连接
                if ($wasTrusted && $actorFd > 0 && (int) $fd === $actorFd) {
                    $payload['data']['trusted'] = true;
                }
            }
            unset($payload);
            $this->pusher->pushPayloadToFds($broadcast['perFd']);

            return true;
        }

        // 无 perFd 时：公共包只带 autoHosted/hostedIndex，不带 trusted
        if (array_key_exists('trusted', $data)) {
            unset($data['trusted']);
        }
        $members = $this->roomMemberService->getActiveInRoom($roomId, 'player_id,fd');
        $this->pusher->pushPlayResultToMembers($members, $data, $turnPid);

        return true;
    }

    /**
     * @param array{round_id?:int,seat?:int,player_id?:int,fd?:int,mode?:string,wait_ops?:list<int>} $turn
     * @return array<string, mixed>|null
     */
    private function buildAutoBody(array $turn): ?array
    {
        $curPid = (int) ($turn['player_id'] ?? 0);
        $curFd = (int) ($turn['fd'] ?? 0);
        $mode = (string) ($turn['mode'] ?? 'discard');
        if ($curPid <= 0) {
            return null;
        }

        $hand = make(GamePlayCardService::class)->getLatestHand((int) ($turn['round_id'] ?? 0), $curPid);
        $decision = make(RedChangMahjongAi::class)->decide($mode === 'claim' ? 'claim' : 'discard', [
            'hand'        => $hand,
            'waitOps'     => is_array($turn['wait_ops'] ?? null) ? $turn['wait_ops'] : [],
            'ups'         => is_array($turn['ups'] ?? null) ? $turn['ups'] : [],
            'claimTile'   => (int) ($turn['claimTile'] ?? 0),
            'kings'       => [HzmjConsts::KING_TILE],
            'discards'    => is_array($turn['discards'] ?? null) ? $turn['discards'] : [],
            'leftTileNum' => (int) ($turn['leftTileNum'] ?? 99),
            'endgame'     => ! empty($turn['endgame']),
        ]);

        if ($mode !== 'claim' && $decision->action === TrustDecision::ACTION_PASS) {
            return null;
        }

        $body = [
            'uid'   => $curPid,
            'fd'    => $curFd,
            '_auto' => true,
            'op'    => $decision->op(),
        ];
        if ($decision->action === TrustDecision::ACTION_DISCARD) {
            $body['tile'] = $decision->tile();
        }

        return $body;
    }

    /**
     * @return array{round_id:int,seat:int,player_id:int,fd:int,mode:string,wait_ops:list<int>}|null
     */
    private function resolveTurn(int $roomId, int $requiredPlayers = 0): ?array
    {
        unset($requiredPlayers); // 创房人数仅作参考；开局后按实际在座判断

        $round = make(GameRoundService::class)->getOne(
            [
                'room_id' => $roomId,
                'status'  => GameRound::STATUS_PLAYING,
            ],
            'id,master_id,status,extra'
        );
        if ($round === null || (int) ($round->master_id ?? 0) <= 0) {
            return null;
        }

        $state = RoundState::decode($round->extra ?? null);
        $phase = (string) ($state['phase'] ?? '');
        if ($phase !== HzmjConsts::PHASE_PLAYING && $phase !== HzmjConsts::PHASE_WAIT) {
            return null;
        }

        $members = $this->roomMemberService->getActiveInRoom($roomId, 'player_id,seat_no,fd');
        // 勿用创房 maxPlayers 卡死：2 人桌 / 有人断线仍应超时代打
        if (count($members) < HzmjConsts::MIN_PLAYERS) {
            return null;
        }

        $bySeat = [];
        foreach ($members as $m) {
            $bySeat[(int) ($m['seat_no'] ?? 0)] = [
                'player_id' => (int) ($m['player_id'] ?? 0),
                'fd'        => (int) ($m['fd'] ?? 0),
            ];
        }

        $waitOps = is_array($state['waitOps'] ?? null) ? $state['waitOps'] : [];
        // 弃牌碰杠问询 / 抢杠胡问询：优先代操作 waitOps 中的座位（有胡优先）
        $claimPending = ! empty($state['pendingDiscard']) || ! empty($state['pendingKongWin']);
        if ($claimPending && $waitOps !== []) {
            $claimSeats = array_map('intval', array_keys($waitOps));
            usort($claimSeats, static function (int $a, int $b) use ($waitOps): int {
                $aWin = in_array(HzmjConsts::SHOW_OP_WIN, array_map('intval', (array) ($waitOps[(string) $a] ?? [])), true);
                $bWin = in_array(HzmjConsts::SHOW_OP_WIN, array_map('intval', (array) ($waitOps[(string) $b] ?? [])), true);
                if ($aWin !== $bWin) {
                    return $aWin ? -1 : 1;
                }

                return $a <=> $b;
            });
            foreach ($claimSeats as $seat) {
                $cur = $bySeat[$seat] ?? null;
                if ($cur === null || (int) $cur['player_id'] <= 0) {
                    continue;
                }

                $pending = is_array($state['pendingDiscard'] ?? null) ? $state['pendingDiscard'] : [];

                $wall = is_array($state['tileWall'] ?? null) ? $state['tileWall'] : [];

                return [
                    'round_id'    => (int) $round->id,
                    'seat'        => $seat,
                    'player_id'   => (int) $cur['player_id'],
                    'fd'          => (int) $cur['fd'],
                    'mode'        => 'claim',
                    'claimTile'   => (int) ($pending['tile'] ?? 0),
                    'wait_ops'    => array_values(array_map('intval', (array) ($waitOps[(string) $seat] ?? []))),
                    'discards'    => is_array($state['discards'] ?? null) ? $state['discards'] : [],
                    'leftTileNum' => count($wall),
                ];
            }

            return null;
        }

        $seat = (int) ($state['curSeat'] ?? 0);
        $cur = $bySeat[$seat] ?? null;
        if ($cur === null || (int) $cur['player_id'] <= 0) {
            return null;
        }

        $allUps = is_array($state['ups'] ?? null) ? $state['ups'] : [];
        $seatUps = $allUps[(string) $seat] ?? $allUps[$seat] ?? [];
        $wall = is_array($state['tileWall'] ?? null) ? $state['tileWall'] : [];

        return [
            'round_id'    => (int) $round->id,
            'seat'        => $seat,
            'player_id'   => (int) $cur['player_id'],
            'fd'          => (int) $cur['fd'],
            'mode'        => 'discard',
            'ups'         => is_array($seatUps) ? $seatUps : [],
            'wait_ops'    => array_values(array_map('intval', (array) ($waitOps[(string) $seat] ?? []))),
            'discards'    => is_array($state['discards'] ?? null) ? $state['discards'] : [],
            'leftTileNum' => count($wall),
            'endgame'     => count($wall) <= 16,
        ];
    }

    /** @param array{player_id?:int}|null $turn */
    private function isTrustedPlayer(int $roomId, ?array $turn): bool
    {
        $pid = (int) ($turn['player_id'] ?? 0);

        return $pid > 0 && $this->roomMemberService->isTrusted($roomId, $pid);
    }
}
