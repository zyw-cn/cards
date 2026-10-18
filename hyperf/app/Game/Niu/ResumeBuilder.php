<?php

declare(strict_types=1);

namespace App\Game\Niu;

use App\Game\Contract\ResumeBuilderInterface;
use App\Model\Game\GameRound;
use App\Service\Game\GamePlayCardService;
use App\Service\Game\GameRoundService;
use App\Service\Game\NiuBetService;
use App\Service\Game\NiuGrabService;
use App\Service\Game\RoomMemberService;
use App\Service\Game\RoomService;
use function Hyperf\Support\make;

/**
 * 斗牛断线重连牌局快照（仅本人可见）
 *
 * 调用链：Room.Join 成功 → 平台 JoinAction 调 Module::resumeBuilder()->build()
 *         → 挂到直返 data.resume（不广播）
 *
 * 仅组装进行中局（STATUS_PLAYING）；已结束 / 未开局返回 null。
 * 人数满后立即发牌；亮牌前本人下发真实手牌（前端遮罩前 2 张明），他人只见牌背。
 *
 * @return array{
 *     phase:string, roundId:int, mySeat:int, myCards:list<string>,
 *     bankerIndex:int, bankerPlayerId:int, grabMultiple:int,
 *     grabMultiples:list<int>, betMultiples:list<int>,
 *     grabs:list<array>, bets:list<array>, seats:list<array>,
 *     playedCount:int, totalCount:int, maxPlayers:int, trusted:bool
 * }|null
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
        $roundId = (int) $round->id;
        $bankerPid = (int) ($round->master_id ?? 0);
        $phase = RoundPhase::phaseOf($round->extra);
        $phaseState = RoundPhase::decode($round->extra);
        $grabMultiple = max(1, (int) ($round->multiple ?? 1));

        $members = make(RoomMemberService::class)->getActiveInRoom($roomId, 'player_id,seat_no');
        $seatByPid = [];
        $seats = [];
        foreach ($members as $m) {
            $pid = (int) $m['player_id'];
            $seat = (int) $m['seat_no'];
            $seatByPid[$pid] = $seat;
            $seats[] = ['seatNo' => $seat, 'playerId' => $pid];
        }
        if (! isset($seatByPid[$playerId])) {
            return null;
        }

        $shownIds = RoundPhase::shownIdsOf($round->extra);
        $shownSet = array_fill_keys($shownIds, true);
        $realCards = make(GamePlayCardService::class)->getLatestHand($roundId, $playerId);
        // 本人始终拿真实手牌；未亮牌时由前端做「2 明 3 暗」与看牌
        $myCards = array_values($realCards);
        foreach ($seats as &$s) {
            $pid = (int) $s['playerId'];
            $hand = make(GamePlayCardService::class)->getLatestHand($roundId, $pid);
            $s['cardsCount'] = count($hand);
            $s['shown'] = isset($shownSet[$pid]);
            // 他人已开牌：重连可看其牌面
            $s['cards'] = isset($shownSet[$pid]) ? array_values($hand) : [];
        }
        unset($s);

        $room = make(RoomService::class)->getOne(['id' => $roomId], 'id,played_count,total_play_count,num');
        $grabs = make(NiuGrabService::class)->loadByRound($roundId);
        $bets = make(NiuBetService::class)->loadByRound($roundId);

        return [
            'phase'          => $phase,
            'roundId'        => $roundId,
            'mySeat'         => $seatByPid[$playerId],
            'myCards'        => $myCards,
            'bankerIndex'    => $bankerPid > 0 ? ($seatByPid[$bankerPid] ?? -1) : -1,
            'bankerPlayerId' => $bankerPid,
            'grabMultiple'   => $grabMultiple,
            'currentPlayerId'=> (int) ($phaseState['current_player_id'] ?? 0),
            'currentIndex'   => (int) ($phaseState['current_seat'] ?? -1),
            'grabMultiples'  => NiuConsts::GRAB_MULTIPLES,
            'betMultiples'   => NiuConsts::BET_MULTIPLES,
            'grabs'          => array_map(static fn (array $g): array => [
                'seat'     => $g['seat'],
                'playerId' => $g['player_id'],
                'multiple' => $g['multiple'],
                'source'   => $g['source'],
            ], $grabs),
            'bets'           => array_map(static fn (array $b): array => [
                'seat'     => $b['seat'],
                'playerId' => $b['player_id'],
                'multiple' => $b['multiple'],
                'amount'   => (float) $b['amount'],
            ], $bets),
            'seats'          => $seats,
            'shownIds'       => $shownIds,
            'playedCount'    => (int) ($room->played_count ?? 0),
            'totalCount'     => (int) ($room->total_play_count ?? 0),
            'maxPlayers'     => (int) ($room->num ?? NiuConsts::MAX_PLAYERS),
            'trusted'        => make(RoomMemberService::class)->isTrusted($roomId, $playerId),
        ];
    }
}
