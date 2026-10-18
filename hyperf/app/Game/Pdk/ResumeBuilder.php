<?php

declare(strict_types=1);

namespace App\Game\Pdk;

use App\Game\Contract\ResumeBuilderInterface;
use App\Game\Pdk\Play\PdkPlayStateResolver;
use App\Model\Game\GameRound;
use App\Service\Game\GamePlayCardService;
use App\Service\Game\GameRoundService;
use App\Service\Game\RoomMemberService;
use App\Service\Game\RoomService;
use function Hyperf\Support\make;

/**
 * 跑得快断线重连牌局快照
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
        $firstPid = (int) ($round->master_id ?? 0);
        $state = RoundState::decode($round->extra ?? null);

        $members = $roomMemberService->getActiveInRoom($roomId, 'player_id,seat_no');
        $seatByPid = [];
        foreach ($members as $m) {
            $seatByPid[(int) $m['player_id']] = (int) $m['seat_no'];
        }
        if (! isset($seatByPid[$playerId])) {
            return null;
        }
        $mySeat = $seatByPid[$playerId];
        $firstSeat = (int) ($state['firstSeat'] ?? ($seatByPid[$firstPid] ?? 0));

        $seats = [];
        $myCards = [];
        foreach ($seatByPid as $pid => $seat) {
            $hand = $gamePlayCardService->getLatestHand($roundId, $pid);
            $seats[] = [
                'seatNo'     => $seat,
                'cardsCount' => count($hand),
                'trusted'    => $roomMemberService->isTrusted($roomId, (int) $pid),
            ];
            if ($pid === $playerId) {
                $myCards = $hand;
            }
        }

        $room = $roomService->getOne(['id' => $roomId], 'id,played_count,total_play_count');
        $trusted = $roomMemberService->isTrusted($roomId, $playerId);
        $phase = (string) ($state['phase'] ?? PdkConsts::PHASE_PLAY);
        if ($phase === PdkConsts::PHASE_DOUBLE) {
            // 旧局加倍阶段：重连时直接迁移到出牌
            $state['phase'] = PdkConsts::PHASE_PLAY;
            $gameRoundService->update($roundId, ['extra' => RoundState::encode($state)]);
            $phase = PdkConsts::PHASE_PLAY;
        }

        $base = [
            'roundId'      => $roundId,
            'mySeat'       => $mySeat,
            'myCards'      => $myCards,
            'firstIndex'   => $firstSeat,
            'bombCount'    => max(0, (int) ($state['bombCount'] ?? 0)),
            'seats'        => $seats,
            'playedCount'  => (int) ($room->played_count ?? 0),
            'totalCount'   => (int) ($room->total_play_count ?? 0),
            'trusted'      => $trusted,
        ];

        $playState = make(PdkPlayStateResolver::class)->resolve(
            $gamePlayCardService,
            $roundId,
            $seatByPid,
            $firstSeat,
            is_array($state['surrendered'] ?? null) ? $state['surrendered'] : []
        );

        return array_merge($base, [
            'phase'           => PdkConsts::PHASE_PLAY,
            'currentIndex'    => $playState['currentIndex'],
            'mustSpade3'      => ! empty($playState['mustLead']) && empty($state['spade3Played']),
            'mustBeat'        => true,
            'lastPlay'        => $playState['lastPlay'],
            'surrenderedSeats'=> is_array($state['surrendered'] ?? null) ? $state['surrendered'] : [],
        ]);
    }
}
