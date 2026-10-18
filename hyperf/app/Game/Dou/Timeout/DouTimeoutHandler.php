<?php

declare(strict_types=1);

namespace App\Game\Dou\Timeout;

use App\Facades\Log;
use App\Game\Contract\TrustDecision;
use App\Game\Dou\Ai\LandlordTrustAi;
use App\Game\Dou\Card\LandlordCard;
use App\Game\Dou\Action\BidAction;
use App\Game\Dou\Action\DoubleAction;
use App\Game\Dou\Action\PlayAction;
use App\Game\Dou\DouConsts;
use App\Game\Dou\RoundState;
use App\Game\Dou\Rule\BidRule;
use App\Model\Game\GameRound;
use App\Service\BaseService;
use App\Service\Game\GamePlayCardService;
use App\Service\Game\GameRoundService;
use App\Service\Game\LandLordBidService;
use App\Service\Game\RoomMemberService;
use App\Game\Platform\Timeout\RoomTimeoutService;
use App\Game\Platform\RoomWsPusher;
use App\Game\Contract\GameTimeoutHandlerInterface;
use App\Game\Contract\TimeoutPhase;
use function Hyperf\Support\make;

/**
 * 斗地主超时策略：叫抢 / 出牌 / 托管代打（归属 Dou Module）
 */
class DouTimeoutHandler extends BaseService implements GameTimeoutHandlerInterface
{
    public const PHASE_BID = 'bid';
    public const PHASE_GRAB = 'grab';
    public const PHASE_DOUBLE = 'double';
    public const PHASE_PLAY = 'play';

    private const MAX_PLAYERS = 3;
    /** 叫/抢地主：超时默认不叫/不抢 */
    private const BID_TIMEOUT = 10;
    /** 叫抢阶段一直无人「叫地主」(maxBid=0) 超过此秒数则解散房间（与 wait 未开局一致） */
    private const GRAB_NO_CALL_DISSOLVE = 60;
    /** 加倍阶段：超时默认不加倍（三家同时，阶段总时限） */
    private const DOUBLE_TIMEOUT = DouConsts::DOUBLE_TIMEOUT;
    private const PLAY_TIMEOUT = 20;
    /** 托管后代打间隔（秒）：轮到即约 2s 后自动出牌 */
    private const TRUSTED_PLAY_TIMEOUT = 2;

    public function __construct(
        protected LandLordBidService $bidService,
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

        $state = RoundState::decode($round->extra ?? null);
        $phase = (string) ($state['phase'] ?? '');

        // 加倍阶段：三家同时选；到点一次性把未选座位补为不加倍
        if ($phase === DouConsts::PHASE_DOUBLE) {
            $pending = BidRule::pendingDoubleSeats($state['farmerDoubles'] ?? []);
            if ($pending === []) {
                return null;
            }
            $remain = BidRule::doubleRemainSeconds($state, self::DOUBLE_TIMEOUT);

            return new TimeoutPhase(
                self::PHASE_DOUBLE,
                $remain,
                0,
            );
        }

        if (
            $phase === DouConsts::PHASE_GRAB
            || (
                (int) $round->master_id === 0
                && $phase !== DouConsts::PHASE_PLAY
                && $phase !== DouConsts::PHASE_DOUBLE
            )
        ) {
            $turn = $this->resolveCurrentTurn($roomId, self::PHASE_BID, $requiredPlayers);
            if ($turn === null) {
                return null;
            }

            $seconds = self::BID_TIMEOUT;
            if ($this->isTrustedPlayer($roomId, $turn)) {
                $seconds = self::TRUSTED_PLAY_TIMEOUT;
            }

            return new TimeoutPhase(
                self::PHASE_BID,
                $seconds,
                (int) ($turn['player_id'] ?? 0),
            );
        }

        $turn = $this->resolveCurrentTurn($roomId, self::PHASE_PLAY, $requiredPlayers);
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
        if ($phase !== self::PHASE_PLAY && $phase !== self::PHASE_BID && $phase !== self::PHASE_DOUBLE) {
            return false;
        }

        $inGame = $this->resolveInGamePhase($roomId);
        if ($inGame === null || $inGame->name !== $phase) {
            return false;
        }

        // 加倍阶段：全员同时，令牌不绑具体玩家
        if ($phase === self::PHASE_DOUBLE) {
            return true;
        }

        if ($expectPlayerId <= 0) {
            return true;
        }

        return $inGame->expectPlayerId === $expectPlayerId;
    }

    public function onTimeout(int $roomId, string $phase, int $requiredPlayers): bool
    {
        return match ($phase) {
            self::PHASE_PLAY                  => $this->onPlayTimeout($roomId, $requiredPlayers),
            self::PHASE_BID, self::PHASE_GRAB => $this->onBidTimeout($roomId),
            self::PHASE_DOUBLE                => $this->autoDouble($roomId),
            default                           => false,
        };
    }

    public function tryImmediateAutoPlay(int $roomId, int $playerId): void
    {
        // 托管不即时代打：由 armGameTimeout + TRUSTED_PLAY_TIMEOUT(2s) 驱动
        if ($roomId <= 0 || $playerId <= 0) {
            return;
        }

        try {
            $inGame = $this->resolveInGamePhase($roomId);
            if ($inGame === null) {
                return;
            }

            if ($inGame->name === self::PHASE_BID || $inGame->name === self::PHASE_GRAB) {
                $turn = $this->resolveCurrentTurn($roomId, self::PHASE_BID);
                if ($turn === null || (int) $turn['player_id'] !== $playerId) {
                    return;
                }
                make(RoomTimeoutService::class)->armGameTimeout($roomId);

                return;
            }

            if ($inGame->name !== self::PHASE_PLAY) {
                return;
            }

            $turn = $this->resolveCurrentTurn($roomId, self::PHASE_PLAY);
            if ($turn === null || (int) $turn['player_id'] !== $playerId) {
                return;
            }

            make(RoomTimeoutService::class)->armGameTimeout($roomId);
        } catch (\Throwable $e) {
            Log::error('斗地主托管代打调度失败', [
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

    // ============================================================
    // 阶段超时处理
    // ============================================================

    private function onPlayTimeout(int $roomId, int $requiredPlayers): bool
    {
        $turn = $this->resolveCurrentTurn($roomId, self::PHASE_PLAY, $requiredPlayers);
        $turnPid = (int) ($turn['player_id'] ?? 0);
        $wasTrusted = $this->isTrustedPlayer($roomId, $turn);

        $data = $this->autoPlay($roomId, $wasTrusted, $turn);
        if ($data === null) {
            return false;
        }

        if ($turnPid > 0 && ! $wasTrusted) {
            if ($this->roomMemberService->registerPlayTimeout($roomId, $turnPid)) {
                $data['autoHosted'] = true;
                $data['hostedIndex'] = (int) ($turn['seat'] ?? $data['playIndex'] ?? $data['passIndex'] ?? -1);
            }
        }

        $members = $this->roomMemberService->getActiveInRoom($roomId, 'player_id,fd');
        $this->pusher->pushPlayResultToMembers($members, $data, $turnPid);

        return true;
    }

    private function onBidTimeout(int $roomId): bool
    {
        if ($this->shouldDissolveGrabNoCall($roomId)) {
            make(RoomTimeoutService::class)->dissolveRoom(
                $roomId,
                '长时间无人叫地主，房间已解散'
            );

            return true;
        }

        return $this->autoBid($roomId);
    }

    /**
     * 叫抢阶段一直无人叫地主（仅不叫/不抢）超过 GRAB_NO_CALL_DISSOLVE 秒
     */
    public function shouldDissolveGrabNoCall(int $roomId): bool
    {
        $round = make(GameRoundService::class)->getOne(
            [
                'room_id' => $roomId,
                'status'  => GameRound::STATUS_PLAYING,
            ],
            'id,master_id,extra'
        );
        if ($round === null || (int) $round->master_id !== 0) {
            return false;
        }

        $state = RoundState::decode($round->extra ?? null);
        if ((string) ($state['phase'] ?? '') !== DouConsts::PHASE_GRAB) {
            return false;
        }

        $loaded = $this->bidService->loadBidActions((int) $round->id);
        if (BidRule::maxBid($loaded['actions']) > 0) {
            return false;
        }

        $started = (int) ($state['grabStartedAt'] ?? 0);
        if ($started <= 0) {
            return false;
        }

        return (time() - $started) >= self::GRAB_NO_CALL_DISSOLVE;
    }

    // ============================================================
    // 轮次解析
    // ============================================================

    /**
     * @return array{round_id:int, seat:int, player_id:int, fd:int, mustLead:bool, lastPlayCards?:list<string>}|null
     */
    private function resolveCurrentTurn(int $roomId, string $phase, int $requiredPlayers = 0): ?array
    {
        $need = $requiredPlayers > 0 ? $requiredPlayers : self::MAX_PLAYERS;

        $round = make(GameRoundService::class)->getOne(
            [
                'room_id' => $roomId,
                'status'  => GameRound::STATUS_PLAYING,
            ],
            'id,master_id,status'
        );
        if ($round === null) {
            return null;
        }

        $roundId = (int) $round->id;
        $masterPid = (int) $round->master_id;
        $members = $this->roomMemberService->getActiveInRoom($roomId, 'player_id,seat_no,fd');
        if (count($members) < $need) {
            return null;
        }

        [$seatByPid, $bySeat] = $this->indexMembersBySeat($members);

        if ($phase === self::PHASE_BID) {
            return $this->resolveBidTurn($roundId, $masterPid, $bySeat);
        }

        return $this->resolvePlayTurn($roundId, $masterPid, $seatByPid, $bySeat);
    }

    /**
     * @param array<int, array{player_id?:int, seat_no?:int, fd?:int}> $members
     * @return array{0: array<int,int>, 1: array<int, array{player_id:int, fd:int}>}
     */
    private function indexMembersBySeat(array $members): array
    {
        $seatByPid = [];
        $bySeat = [];
        foreach ($members as $m) {
            $pid = (int) ($m['player_id'] ?? 0);
            $seat = (int) ($m['seat_no'] ?? 0);
            $seatByPid[$pid] = $seat;
            $bySeat[$seat] = ['player_id' => $pid, 'fd' => (int) ($m['fd'] ?? 0)];
        }

        return [$seatByPid, $bySeat];
    }

    /**
     * @param array<int, array{player_id:int, fd:int}> $bySeat
     * @return array{round_id:int, seat:int, player_id:int, fd:int, mustLead:bool}|null
     */
    private function resolveBidTurn(int $roundId, int $masterPid, array $bySeat): ?array
    {
        if ($masterPid !== 0) {
            return null;
        }

        $loaded = $this->bidService->loadBidActions($roundId);
        $status = BidRule::status($loaded['first'], $loaded['actions']);
        if ($status['next'] === null) {
            return null;
        }

        $seat = (int) $status['next'];
        $cur = $bySeat[$seat] ?? null;
        if ($cur === null) {
            return null;
        }

        return [
            'round_id'  => $roundId,
            'seat'      => $seat,
            'player_id' => (int) $cur['player_id'],
            'fd'        => (int) $cur['fd'],
            'mustLead'  => false,
        ];
    }

    /**
     * @param array<int, int> $seatByPid
     * @param array<int, array{player_id:int, fd:int}> $bySeat
     * @return array{round_id:int, seat:int, player_id:int, fd:int, mustLead:bool, lastPlayCards:list<string>}|null
     */
    private function resolvePlayTurn(int $roundId, int $masterPid, array $seatByPid, array $bySeat): ?array
    {
        if ($masterPid === 0) {
            return null;
        }

        $masterSeat = $seatByPid[$masterPid] ?? 0;
        $state = make(GamePlayCardService::class)->resolvePlayState(
            $roundId,
            $seatByPid,
            $masterSeat,
            self::MAX_PLAYERS
        );

        $seat = (int) ($state['currentIndex'] ?? $masterSeat);
        $mustLead = ! empty($state['mustLead']);
        $lastPlayCards = [];
        if (! $mustLead && is_array($state['lastPlay']['cards'] ?? null)) {
            $lastPlayCards = $state['lastPlay']['cards'];
        }

        $cur = $bySeat[$seat] ?? null;
        if ($cur === null) {
            return null;
        }

        return [
            'round_id'      => $roundId,
            'seat'          => $seat,
            'player_id'     => (int) $cur['player_id'],
            'fd'            => (int) $cur['fd'],
            'mustLead'      => $mustLead,
            'lastPlayCards' => $lastPlayCards,
            'trustCtx'      => $this->trustContext($roundId, $seat, $masterSeat, $state, $seatByPid),
        ];
    }

    /**
     * 托管用：座位角色、地主余牌、上手来源（供上下家顶/喂）。
     *
     * @param array<string, mixed> $state
     * @param array<int, int> $seatByPid
     * @return array{
     *   threat:bool,
     *   partnerLead:bool,
     *   mustLead:bool,
     *   mySeat:int,
     *   landlordSeat:int,
     *   lastSeat:int,
     *   seatCounts:array<int,int>,
     *   maxPlayers:int,
     *   role:string
     * }
     */
    private function trustContext(int $roundId, int $seat, int $landlordSeat, array $state, array $seatByPid): array
    {
        $cardSvc = make(GamePlayCardService::class);
        $counts = [];
        foreach ($seatByPid as $pid => $memberSeat) {
            $counts[(int) $memberSeat] = count($cardSvc->getLatestHand($roundId, (int) $pid));
        }
        $oppMin = 99;
        foreach ($counts as $memberSeat => $count) {
            if ($memberSeat === $seat) {
                continue;
            }
            $isOpp = $seat === $landlordSeat || $memberSeat === $landlordSeat;
            if ($isOpp) {
                $oppMin = min($oppMin, $count);
            }
        }
        $mustLead = ! empty($state['mustLead']);
        $lastSeat = -1;
        if (! $mustLead && isset($state['lastPlay']['index'])) {
            $lastSeat = (int) $state['lastPlay']['index'];
        }
        $partnerLead = $seat !== $landlordSeat
            && $lastSeat >= 0
            && $lastSeat !== $seat
            && $lastSeat !== $landlordSeat;

        return [
            'threat'       => $oppMin <= 2 || ((int) ($counts[$landlordSeat] ?? 99)) <= 2,
            'partnerLead'  => $partnerLead,
            'mustLead'     => $mustLead,
            'mySeat'       => $seat,
            'landlordSeat' => $landlordSeat,
            'lastSeat'     => $lastSeat,
            'seatCounts'   => $counts,
            'maxPlayers'   => self::MAX_PLAYERS,
            'role'         => LandlordTrustAi::farmerRole($seat, $landlordSeat, self::MAX_PLAYERS),
        ];
    }

    /** @param array{player_id?:int}|null $turn */
    private function isTrustedPlayer(int $roomId, ?array $turn): bool
    {
        $pid = (int) ($turn['player_id'] ?? 0);

        return $pid > 0 && $this->roomMemberService->isTrusted($roomId, $pid);
    }

    // ============================================================
    // 自动行动
    // ============================================================

    /**
     * @param array{round_id?:int,seat?:int,player_id?:int,fd?:int,mustLead?:bool,lastPlayCards?:list<string>}|null $turn
     * @return array<string, mixed>|null
     */
    private function autoPlay(int $roomId, bool $asTrusted = false, ?array $turn = null): ?array
    {
        $turn ??= $this->resolveCurrentTurn($roomId, self::PHASE_PLAY);
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
        $ctx = is_array($turn['trustCtx'] ?? null) ? $turn['trustCtx'] : [];
        $ctx['mustLead'] = $mustLead;

        // 托管走 GameTrustAi；非托管超时仅终局一手或必须首出
        if ($asTrusted) {
            $decision = make(LandlordTrustAi::class)->decide('play', array_merge($ctx, [
                'hand'      => $hand,
                'lastCards' => $lastCards,
                'mustLead'  => $mustLead,
            ]));
            $cards = $decision->action === TrustDecision::ACTION_PLAY ? $decision->cards() : [];
        } else {
            $cards = LandlordCard::suggestFinishPlay($hand, $lastCards);
            if ($cards === [] && $mustLead) {
                $cards = LandlordCard::suggestPlay($hand, [], $ctx);
            }
        }

        $result = make(PlayAction::class)->run([
            'uid'   => $curPid,
            'fd'    => $curFd,
            'cards' => $cards,
            '_auto' => true,
        ]);

        if ((int) ($result['code'] ?? -1) !== 0) {
            return null;
        }

        $data = $result['data'] ?? null;
        return is_array($data) ? $data : null;
    }

    private function autoBid(int $roomId): bool
    {
        $turn = $this->resolveCurrentTurn($roomId, self::PHASE_BID);
        if ($turn === null) {
            return false;
        }

        $curPid = (int) $turn['player_id'];
        $curFd = (int) $turn['fd'];
        if ($curPid <= 0) {
            return false;
        }

        // 托管/超时叫抢：AI 固定 PASS_CALL（不叫/不抢）
        $bid = make(LandlordTrustAi::class)->decide('bid');
        $result = make(BidAction::class)->run([
            'uid'    => $curPid,
            'fd'     => $curFd,
            'score'  => $bid->score(),
            'action' => $bid->action,
            '_auto'  => true,
        ]);
        if ((int) ($result['code'] ?? -1) !== 0) {
            return false;
        }

        $broadcast = $result['_broadcast'] ?? null;
        if (is_array($broadcast) && ! empty($broadcast['perFd'])) {
            $this->pusher->pushPayloadToFds($broadcast['perFd']);
            return true;
        }

        $data = $result['data'] ?? null;
        if (is_array($data)) {
            $members = $this->roomMemberService->getActiveInRoom($roomId, 'player_id,fd');
            $this->pusher->pushDataToMembers($members, $data);
        }

        return true;
    }

    /**
     * 加倍阶段取一名未选玩家（超时代操入口）
     *
     * @return array{round_id:int, seat:int, player_id:int, fd:int, mustLead:bool}|null
     */
    private function resolveDoubleTurn(int $roomId, ?array $state = null): ?array
    {
        $round = make(GameRoundService::class)->getOne(
            ['room_id' => $roomId, 'status' => GameRound::STATUS_PLAYING],
            'id,extra,status'
        );
        if ($round === null) {
            return null;
        }
        $state ??= RoundState::decode($round->extra ?? null);
        if (($state['phase'] ?? '') !== DouConsts::PHASE_DOUBLE) {
            return null;
        }

        $pending = BidRule::pendingDoubleSeats($state['farmerDoubles'] ?? []);
        if ($pending === []) {
            return null;
        }

        $members = $this->roomMemberService->getActiveInRoom($roomId, 'player_id,seat_no,fd');
        [, $bySeat] = $this->indexMembersBySeat($members);
        $seat = $pending[0];
        $cur = $bySeat[$seat] ?? null;
        if ($cur === null) {
            return null;
        }

        return [
            'round_id'  => (int) $round->id,
            'seat'      => $seat,
            'player_id' => (int) $cur['player_id'],
            'fd'        => (int) $cur['fd'],
            'mustLead'  => false,
        ];
    }

    /** 加倍超时：所有未选座位一次性默认不加倍并进入出牌 */
    private function autoDouble(int $roomId): bool
    {
        $turn = $this->resolveDoubleTurn($roomId);
        if ($turn === null) {
            return false;
        }

        $curPid = (int) $turn['player_id'];
        $curFd = (int) $turn['fd'];
        if ($curPid <= 0) {
            return false;
        }

        $result = make(DoubleAction::class)->run([
            'uid'       => $curPid,
            'fd'        => $curFd,
            'score'     => 0,
            '_auto'     => true,
            '_auto_all' => true,
        ]);
        if ((int) ($result['code'] ?? -1) !== 0) {
            Log::warning('加倍超时自动不加倍失败', [
                'room_id'   => $roomId,
                'player_id' => $curPid,
                'message'   => $result['message'] ?? '',
            ]);

            return false;
        }

        $broadcast = $result['_broadcast'] ?? null;
        if (is_array($broadcast) && ! empty($broadcast['perFd'])) {
            $this->pusher->pushPayloadToFds($broadcast['perFd']);

            return true;
        }

        $data = $result['data'] ?? null;
        if (is_array($data)) {
            $members = $this->roomMemberService->getActiveInRoom($roomId, 'player_id,fd');
            $this->pusher->pushDataToMembers($members, $data);
        }

        return true;
    }
}
