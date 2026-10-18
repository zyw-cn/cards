<?php

declare(strict_types=1);

namespace App\Game\Dou;

use App\Game\Contract\ResumeBuilderInterface;
use App\Game\Dou\Rule\BidRule;
use App\Model\Game\GameRound;
use App\Service\Game\GamePlayCardService;
use App\Service\Game\GameRoundService;
use App\Service\Game\LandLordBidService;
use App\Service\Game\RoomMemberService;
use App\Service\Game\RoomService;
use function Hyperf\Support\make;

/**
 * 断线重连牌局快照（仅本人可见，含手牌）
 */
class ResumeBuilder implements ResumeBuilderInterface
{
    /**
     * @return array<string, mixed>|null
     */
    public function build(int $roomId, int $playerId): ?array
    {
        if ($roomId <= 0 || $playerId <= 0) {
            return null;
        }

        $gameRoundService = make(GameRoundService::class);
        $roomMemberService = make(RoomMemberService::class);
        $gamePlayCardService = make(GamePlayCardService::class);
        $roomService = make(RoomService::class);

        $round = $gameRoundService->getOne([
            'room_id' => $roomId,
            'status'  => GameRound::STATUS_PLAYING,
        ]);
        if ($round === null) {
            return null;
        }
        $roundId = (int) $round->id;
        $masterId = (int) ($round->master_id ?? 0);
        $state = RoundState::decode($round->extra ?? null);
        $phase = (string) ($state['phase'] ?? DouConsts::PHASE_PLAY);

        $members = $roomMemberService->getActiveInRoom($roomId, 'player_id,seat_no');
        $seatByPid = [];
        foreach ($members as $m) {
            $seatByPid[(int) $m['player_id']] = (int) $m['seat_no'];
        }
        if (! isset($seatByPid[$playerId])) {
            return null;
        }
        $mySeat = $seatByPid[$playerId];

        $seats = [];
        $myCards = [];
        foreach ($seatByPid as $pid => $seat) {
            $hand = $gamePlayCardService->getLatestHand($roundId, $pid);
            $seats[] = ['seatNo' => $seat, 'cardsCount' => count($hand)];
            if ($pid === $playerId) {
                $myCards = $hand;
            }
        }

        $bottom = array_values($state['remain'] ?? []);
        $room = $roomService->getOne(['id' => $roomId], 'id,played_count,total_play_count');
        $playedCount = (int) ($room->played_count ?? 0);
        $totalCount = (int) ($room->total_play_count ?? 0);
        $trusted = $roomMemberService->isTrusted($roomId, $playerId);
        $multiple = max(1, (int) ($state['landlordMultiple'] ?? 1));

        if ($phase === DouConsts::PHASE_GRAB || ($masterId === 0 && $phase !== DouConsts::PHASE_PLAY && $phase !== DouConsts::PHASE_DOUBLE)) {
            $bid = $this->grabPhaseState($roundId);
            $bottomBacks = array_fill(0, max(0, count($bottom)), 'back');

            return [
                'phase'          => DouConsts::PHASE_GRAB,
                'askMode'        => (int) ($state['askMode'] ?? DouConsts::ASK_GRAB),
                'roundId'        => $roundId,
                'mySeat'         => $mySeat,
                'myCards'        => $myCards,
                'landlordIndex'  => -1,
                'multiple'       => max(1, (int) ($bid['multiple'] ?? 1)),
                'bidTurn'        => $bid['bidTurn'],
                'currentIndex'   => $bid['bidTurn'],
                'maxBid'         => (int) ($bid['maxBid'] ?? 0),
                'grabScores'     => DouConsts::GRAB_SCORES,
                'bottom'         => $bottomBacks,
                'bottomRevealed' => false,
                'seats'          => $seats,
                'lastPlay'       => null,
                'playedCount'    => $playedCount,
                'totalCount'     => $totalCount,
                'trusted'        => $trusted,
            ];
        }

        if ($phase === DouConsts::PHASE_DOUBLE) {
            $landlordIndex = (int) ($state['landlordSeat'] ?? ($seatByPid[$masterId] ?? -1));

            return [
                'phase'          => DouConsts::PHASE_DOUBLE,
                'askMode'        => (int) ($state['askMode'] ?? DouConsts::ASK_GRAB),
                'roundId'        => $roundId,
                'mySeat'         => $mySeat,
                'myCards'        => $myCards,
                'landlordIndex'  => $landlordIndex,
                'multiple'       => $multiple,
                'bidTurn'        => -1,
                'currentIndex'   => -1,
                'doubleTurn'     => -1,
                'doubleSeconds'  => BidRule::doubleRemainSeconds($state),
                'doubleScores'   => DouConsts::DOUBLE_SCORES,
                'farmerDoubles'  => $state['farmerDoubles'] ?? [],
                'bottom'         => $bottom,
                'bottomRevealed' => true,
                'seats'          => $seats,
                'lastPlay'       => null,
                'playedCount'    => $playedCount,
                'totalCount'     => $totalCount,
                'trusted'        => $trusted,
            ];
        }

        $landlordIndex = $seatByPid[$masterId] ?? (int) ($state['landlordSeat'] ?? -1);
        $playState = $this->playPhaseState($roundId, $landlordIndex, $seatByPid);
        $bombCount = max(0, (int) ($state['bombCount'] ?? 0));
        $tableMultiple = BidRule::tableMultiple($multiple, $bombCount);

        return [
            'phase'          => DouConsts::PHASE_PLAY,
            'askMode'        => (int) ($state['askMode'] ?? DouConsts::ASK_GRAB),
            'roundId'        => $roundId,
            'mySeat'         => $mySeat,
            'myCards'        => $myCards,
            'landlordIndex'  => $landlordIndex,
            'multiple'       => $tableMultiple,
            'bombCount'      => $bombCount,
            'farmerDoubles'  => $state['farmerDoubles'] ?? [],
            'bidTurn'        => -1,
            'currentIndex'   => $playState['currentIndex'],
            'bottom'         => $bottom,
            'bottomRevealed' => true,
            'seats'          => $seats,
            'lastPlay'       => $playState['lastPlay'],
            'playedCount'    => $playedCount,
            'totalCount'     => $totalCount,
            'trusted'        => $trusted,
        ];
    }

    /** @return array{bidTurn: int, maxBid: int, multiple: int} */
    private function grabPhaseState(int $roundId): array
    {
        $bidService = make(LandLordBidService::class);
        $loaded = $bidService->loadBidActions($roundId);
        $status = BidRule::status($loaded['first'], $loaded['actions']);
        $multiple = $bidService->calcMultiple($loaded['actions']);

        return [
            'bidTurn'  => $status['next'] === null ? $loaded['first'] : (int) $status['next'],
            'maxBid'   => (int) ($status['maxBid'] ?? BidRule::maxBid($loaded['actions'])),
            'multiple' => max(1, $multiple),
        ];
    }

    /**
     * @param array<int,int> $seatByPid
     * @return array{currentIndex: int, lastPlay: array{index:int, cards:list<string>}|null}
     */
    private function playPhaseState(int $roundId, int $landlordIndex, array $seatByPid): array
    {
        $state = make(GamePlayCardService::class)->resolvePlayState(
            $roundId,
            $seatByPid,
            $landlordIndex,
            DouConsts::MAX_PLAYERS
        );

        return [
            'currentIndex' => $state['currentIndex'],
            'lastPlay'     => $state['lastPlay'],
        ];
    }
}
