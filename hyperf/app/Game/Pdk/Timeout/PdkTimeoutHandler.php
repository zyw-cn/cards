<?php

declare(strict_types=1);

namespace App\Game\Pdk\Timeout;

use App\Facades\Log;
use App\Game\Contract\GameTimeoutHandlerInterface;
use App\Game\Contract\TimeoutPhase;
use App\Game\Contract\TrustDecision;
use App\Game\Pdk\Action\DoubleAction;
use App\Game\Pdk\Action\PlayAction;
use App\Game\Pdk\Ai\PdkAi;
use App\Game\Pdk\Card\PdkCard;
use App\Game\Pdk\PdkConsts;
use App\Game\Pdk\Play\PdkPlayStateResolver;
use App\Game\Pdk\Play\SurrenderHelper;
use App\Game\Pdk\RoundState;
use App\Game\Pdk\Rule\DoubleRule;
use App\Game\Platform\RoomWsPusher;
use App\Game\Platform\Timeout\RoomTimeoutService;
use App\Model\Game\GameRound;
use App\Service\BaseService;
use App\Service\Game\GamePlayCardService;
use App\Service\Game\GameRoundService;
use App\Service\Game\RoomMemberService;
use function Hyperf\Support\make;

/**
 * 跑得快超时：加倍（20s 默认不加倍）/ 出牌（20s）/ 管不上与客户端短闹钟对齐（5s）/ 托管约 2s 代打
 */
class PdkTimeoutHandler extends BaseService implements GameTimeoutHandlerInterface
{
    public const PHASE_DOUBLE = 'double';

    public const PHASE_PLAY = 'play';

    private const DOUBLE_TIMEOUT = 20;

    private const PLAY_TIMEOUT = 20;

    /** 跟牌管不上：与客户端 PLAY_CANNOT_BEAT 一致，倒计时走完再自动过牌 */
    private const CANNOT_BEAT_TIMEOUT = 5;

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
                ['status', '<', GameRound::STATUS_ENDED],
            ],
            'id,master_id,status,extra'
        );

        if ($round === null || (int) $round->status !== GameRound::STATUS_PLAYING) {
            return null;
        }
        if ((int) ($round->master_id ?? 0) <= 0) {
            return null;
        }

        $state = RoundState::decode($round->extra ?? null);
        $phase = (string) ($state['phase'] ?? '');

        if ($phase === PdkConsts::PHASE_DOUBLE) {
            $pending = $this->resolvePendingDoubles($roomId, $state);
            if ($pending === []) {
                return null;
            }

            // 同时加倍：房间级超时，超时后未选玩家统一不加倍
            return new TimeoutPhase(
                self::PHASE_DOUBLE,
                self::DOUBLE_TIMEOUT,
                0,
            );
        }

        $turn = $this->resolvePlayTurn($roomId, $requiredPlayers);
        if ($turn === null) {
            return null;
        }

        $seconds = self::PLAY_TIMEOUT;
        // 跟牌且完全管不上：等短闹钟结束再过牌，避免桌上倒计时还在就进入下一家。
        if ($this->mustAutoPass($turn)) {
            $seconds = self::CANNOT_BEAT_TIMEOUT;
        } elseif ($this->isTrustedPlayer($roomId, $turn)) {
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
        if ($phase !== self::PHASE_PLAY && $phase !== self::PHASE_DOUBLE) {
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
        if ($phase === self::PHASE_DOUBLE) {
            return $this->autoDouble($roomId);
        }
        if ($phase !== self::PHASE_PLAY) {
            return false;
        }

        return $this->onPlayTimeout($roomId, $requiredPlayers);
    }

    public function tryImmediateAutoPlay(int $roomId, int $playerId): void
    {
        // 托管不即时代打：由 armGameTimeout + TRUSTED_PLAY_TIMEOUT(2s) 驱动
        if ($roomId <= 0 || $playerId <= 0) {
            return;
        }

        try {
            $inGame = $this->resolveInGamePhase($roomId);
            if ($inGame === null || $inGame->name !== self::PHASE_PLAY) {
                return;
            }

            $turn = $this->resolvePlayTurn($roomId);
            if ($turn === null || (int) $turn['player_id'] !== $playerId) {
                return;
            }

            make(RoomTimeoutService::class)->armGameTimeout($roomId);
        } catch (\Throwable $e) {
            Log::error('跑得快托管代打调度失败', [
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

    private function onPlayTimeout(int $roomId, int $requiredPlayers): bool
    {
        $turn = $this->resolvePlayTurn($roomId, $requiredPlayers);
        $turnPid = (int) ($turn['player_id'] ?? 0);
        $wasTrusted = $this->isTrustedPlayer($roomId, $turn);
        $forcedPass = $this->mustAutoPass($turn);

        $data = $this->autoPlay($roomId, $wasTrusted, $turn);
        if ($data === null) {
            return false;
        }

        if ($forcedPass) {
            $data['autoPass'] = true;
        }

        // 管不上自动过牌属于规则动作，不算操作超时，也不累计自动托管次数。
        if ($turnPid > 0 && ! $wasTrusted && ! $forcedPass) {
            if ($this->roomMemberService->registerPlayTimeout($roomId, $turnPid)) {
                $data['autoHosted'] = true;
                $data['hostedIndex'] = (int) ($turn['seat'] ?? $data['playIndex'] ?? $data['passIndex'] ?? -1);
            }
        }

        $members = $this->roomMemberService->getActiveInRoom($roomId, 'player_id,fd');
        $this->pusher->pushPlayResultToMembers($members, $data, $turnPid);

        return true;
    }

    /**
     * 当前玩家是否必须立即自动过牌：不是领出、有上家牌，且整手牌没有任何合法管法。
     *
     * @param array{round_id?:int,player_id?:int,mustLead?:bool,lastPlayCards?:list<string>}|null $turn
     */
    private function mustAutoPass(?array $turn): bool
    {
        if ($turn === null || ! empty($turn['mustLead'])) {
            return false;
        }
        $lastCards = is_array($turn['lastPlayCards'] ?? null)
            ? $turn['lastPlayCards']
            : [];
        if ($lastCards === []) {
            return false;
        }
        $roundId = (int) ($turn['round_id'] ?? 0);
        $playerId = (int) ($turn['player_id'] ?? 0);
        if ($roundId <= 0 || $playerId <= 0) {
            return false;
        }

        $hand = make(GamePlayCardService::class)->getLatestHand($roundId, $playerId);

        return $hand !== [] && ! PdkCard::canBeat($hand, $lastCards);
    }

    /**
     * @return array{round_id:int, seat:int, player_id:int, fd:int, mustLead:bool, lastPlayCards:list<string>, needSpade3:bool, opponentOneCard:bool}|null
     */
    private function resolvePlayTurn(int $roomId, int $requiredPlayers = 0): ?array
    {
        $need = $requiredPlayers > 0 ? $requiredPlayers : PdkConsts::MAX_PLAYERS;

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

        $roundId = (int) $round->id;
        $firstPid = (int) $round->master_id;
        $state = RoundState::decode($round->extra ?? null);
        if (($state['phase'] ?? '') !== PdkConsts::PHASE_PLAY) {
            return null;
        }
        $members = $this->roomMemberService->getActiveInRoom($roomId, 'player_id,seat_no,fd');
        if (count($members) < $need) {
            return null;
        }

        $seatByPid = [];
        $bySeat = [];
        $firstSeat = (int) ($state['firstSeat'] ?? 0);
        foreach ($members as $m) {
            $pid = (int) ($m['player_id'] ?? 0);
            $seat = (int) ($m['seat_no'] ?? 0);
            $seatByPid[$pid] = $seat;
            $bySeat[$seat] = ['player_id' => $pid, 'fd' => (int) ($m['fd'] ?? 0)];
            if ($pid === $firstPid) {
                $firstSeat = $seat;
            }
        }

        $surrendered = is_array($state['surrendered'] ?? null) ? $state['surrendered'] : [];
        $playState = make(PdkPlayStateResolver::class)->resolve(
            make(GamePlayCardService::class),
            $roundId,
            $seatByPid,
            $firstSeat,
            $surrendered
        );

        $seat = (int) ($playState['currentIndex'] ?? $firstSeat);
        $mustLead = ! empty($playState['mustLead']);
        $lastPlayCards = [];
        if (! $mustLead && is_array($playState['lastPlay']['cards'] ?? null)) {
            $lastPlayCards = $playState['lastPlay']['cards'];
        }

        $cur = $bySeat[$seat] ?? null;
        if ($cur === null) {
            return null;
        }

        $currentPid = (int) $cur['player_id'];
        if (SurrenderHelper::isSurrendered($seat, $surrendered)) {
            return null;
        }
        $nextSeat = ($seat + 1) % PdkConsts::MAX_PLAYERS;
        $forceLargestSingle = false;
        if (! SurrenderHelper::isSurrendered($nextSeat, $surrendered)) {
            $next = $bySeat[$nextSeat] ?? null;
            $nextPid = (int) ($next['player_id'] ?? 0);
            if ($nextPid > 0) {
                $nextCount = count(make(GamePlayCardService::class)->getLatestHand($roundId, $nextPid));
                $forceLargestSingle = $nextCount === 1;
            }
        }

        return [
            'round_id'            => $roundId,
            'seat'                => $seat,
            'player_id'           => $currentPid,
            'fd'                  => (int) $cur['fd'],
            'mustLead'            => $mustLead,
            'lastPlayCards'       => $lastPlayCards,
            'needSpade3'          => false,
            'forceLargestSingle'  => $forceLargestSingle,
            'forceLargestPair'    => false,
            // 兼容旧字段
            'opponentOneCard'     => $forceLargestSingle,
        ];
    }

    /**
     * @param array<string, mixed>|null $state
     * @return list<array{seat:int, player_id:int, fd:int}>
     */
    private function resolvePendingDoubles(int $roomId, ?array $state = null): array
    {
        if ($state === null) {
            $round = make(GameRoundService::class)->getOne(
                [
                    'room_id' => $roomId,
                    'status'  => GameRound::STATUS_PLAYING,
                ],
                'id,extra'
            );
            if ($round === null) {
                return [];
            }
            $state = RoundState::decode($round->extra ?? null);
        }
        if (($state['phase'] ?? '') !== PdkConsts::PHASE_DOUBLE) {
            return [];
        }

        $doubles = is_array($state['doubles'] ?? null) ? $state['doubles'] : [];
        $pendingSeats = DoubleRule::pendingSeats($doubles);
        if ($pendingSeats === []) {
            return [];
        }

        $members = $this->roomMemberService->getActiveInRoom($roomId, 'player_id,seat_no,fd');
        $bySeat = [];
        foreach ($members as $m) {
            $bySeat[(int) ($m['seat_no'] ?? 0)] = [
                'player_id' => (int) ($m['player_id'] ?? 0),
                'fd'        => (int) ($m['fd'] ?? 0),
            ];
        }

        $out = [];
        foreach ($pendingSeats as $seat) {
            $cur = $bySeat[$seat] ?? null;
            if ($cur === null || (int) $cur['player_id'] <= 0) {
                continue;
            }
            $out[] = [
                'seat'      => $seat,
                'player_id' => (int) $cur['player_id'],
                'fd'        => (int) $cur['fd'],
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed>|null $state
     * @return array{seat:int, player_id:int, fd:int}|null
     */
    private function resolveDoubleTurn(int $roomId, ?array $state = null): ?array
    {
        $pending = $this->resolvePendingDoubles($roomId, $state);

        return $pending[0] ?? null;
    }

    /** 加倍超时：未选玩家统一默认不加倍（同时加倍） */
    private function autoDouble(int $roomId): bool
    {
        $pending = $this->resolvePendingDoubles($roomId);
        if ($pending === []) {
            return false;
        }

        $anyOk = false;
        foreach ($pending as $turn) {
            $result = make(DoubleAction::class)->run([
                'uid'   => (int) $turn['player_id'],
                'fd'    => (int) $turn['fd'],
                'score' => 0,
                '_auto' => true,
            ]);

            if (($result['code'] ?? 1) !== 0) {
                Log::warning('跑得快加倍超时自动不加倍失败', [
                    'room_id' => $roomId,
                    'pid'     => $turn['player_id'],
                    'message' => $result['message'] ?? '',
                ]);
                continue;
            }
            $anyOk = true;

            $broadcast = is_array($result['_broadcast'] ?? null) ? $result['_broadcast'] : [];
            if (! empty($broadcast['perFd']) && is_array($broadcast['perFd'])) {
                $this->pusher->pushPayloadToFds($broadcast['perFd']);
                continue;
            }

            $data = is_array($result['data'] ?? null) ? $result['data'] : null;
            if ($data === null) {
                continue;
            }
            $members = $this->roomMemberService->getActiveInRoom($roomId, 'player_id,fd');
            $this->pusher->pushPlayResultToMembers($members, $data, (int) $turn['player_id']);
        }

        return $anyOk;
    }

    /** @param array{player_id?:int}|null $turn */
    private function isTrustedPlayer(int $roomId, ?array $turn): bool
    {
        $pid = (int) ($turn['player_id'] ?? 0);

        return $pid > 0 && $this->roomMemberService->isTrusted($roomId, $pid);
    }

    /**
     * @param array{round_id?:int,seat?:int,player_id?:int,fd?:int,mustLead?:bool,lastPlayCards?:list<string>,needSpade3?:bool,opponentOneCard?:bool}|null $turn
     * @return array<string, mixed>|null
     */
    private function autoPlay(int $roomId, bool $asTrusted = false, ?array $turn = null): ?array
    {
        $turn ??= $this->resolvePlayTurn($roomId);
        if ($turn === null) {
            return null;
        }

        $roundId = (int) $turn['round_id'];
        $curPid = (int) $turn['player_id'];
        $curFd = (int) $turn['fd'];
        if ($curPid <= 0) {
            return null;
        }

        $hand = make(GamePlayCardService::class)->getLatestHand($roundId, $curPid);
        if ($hand === []) {
            return null;
        }

        $mustLead = ! empty($turn['mustLead']);
        $lastCards = $mustLead ? [] : (array) ($turn['lastPlayCards'] ?? []);
        $decision = make(PdkAi::class)->decide('play', [
            'hand'               => $hand,
            'lastCards'          => $lastCards,
            'mustLead'           => $mustLead,
            'mySeat'             => (int) ($turn['seat'] ?? -1),
            'forceLargestSingle' => ! empty($turn['forceLargestSingle']) || ! empty($turn['opponentOneCard']),
            'opponentOneCard'    => ! empty($turn['opponentOneCard']),
            'needSpade3'         => ! empty($turn['needSpade3']),
            'maxPlayers'         => PdkConsts::MAX_PLAYERS,
        ]);
        $cards = $decision->action === TrustDecision::ACTION_PLAY ? $decision->cards() : [];

        $result = make(PlayAction::class)->run([
            'uid'   => $curPid,
            'fd'    => $curFd,
            'cards' => $cards,
            '_auto' => true,
        ]);

        if (($result['code'] ?? 1) !== 0) {
            Log::warning('跑得快超时代打失败', [
                'room_id' => $roomId,
                'pid'     => $curPid,
                'cards'   => $cards,
                'message' => $result['message'] ?? '',
            ]);

            return null;
        }

        return is_array($result['data'] ?? null) ? $result['data'] : null;
    }
}
