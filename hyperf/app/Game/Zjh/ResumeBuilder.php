<?php

declare(strict_types=1);

namespace App\Game\Zjh;

use App\Game\Contract\ResumeBuilderInterface;
use App\Model\Game\GameRound;
use App\Service\Game\GamePlayCardService;
use App\Service\Game\GameRoundService;
use App\Service\Game\RoomMemberService;
use App\Service\Game\RoomService;
use function Hyperf\Support\make;

/**
 * 炸金花断线重连快照（仅本人可见手牌，且须已看牌）
 */
class ResumeBuilder implements ResumeBuilderInterface
{
    public function build(int $roomId, int $playerId): ?array
    {
        if ($roomId <= 0 || $playerId <= 0) {
            return null;
        }

        $round = make(GameRoundService::class)->getOne([
            'room_id' => $roomId,
            'status'  => GameRound::STATUS_PLAYING,
        ]);
        if ($round === null) {
            return null;
        }

        $state = RoundState::decode($round->extra);
        if ($state['phase'] !== ZjhConsts::PHASE_BET) {
            return null;
        }

        $mySeat = -1;
        foreach ($state['seatPids'] ?? [] as $sk => $pid) {
            if ((int) $pid === $playerId) {
                $mySeat = (int) $sk;
                break;
            }
        }
        if ($mySeat < 0) {
            return null;
        }

        $looked = RoundState::isLooked($state, $mySeat);
        $myCards = [];
        if ($looked && ! RoundState::isFolded($state, $mySeat)) {
            $myCards = make(GamePlayCardService::class)->getLatestHand((int) $round->id, $playerId);
        }

        $room = make(RoomService::class)->getOne(['id' => $roomId], 'id,played_count,total_play_count,num,rule');
        $rule = $room->rule ?? null;
        $table = Support::publicTable($state, '', (int) $round->id, $rule);
        $trusted = (bool) (make(RoomMemberService::class)->getActiveByPlayer($playerId)?->is_auto ?? 0);
        $caps = ZjhConsts::capsFromRule($rule);

        $dealerPid = RoundState::playerIdOf($state, (int) ($state['dealerSeat'] ?? 0));

        return array_merge($table, [
            'mySeat'           => $mySeat,
            'myCards'          => $myCards,
            'looked'           => $looked,
            'folded'           => RoundState::isFolded($state, $mySeat),
            'callAmount'       => (float) RoundState::callAmount($state, $mySeat, $caps['select_points']),
            'dealerPlayerId'   => $dealerPid,
            'playedCount'      => (int) ($room->played_count ?? 0),
            'totalCount'       => (int) ($room->total_play_count ?? 0),
            'maxPlayers'       => (int) ($room->num ?? ZjhConsts::MAX_PLAYERS),
            'trusted'          => $trusted,
        ]);
    }
}
