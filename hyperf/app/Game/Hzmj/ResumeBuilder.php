<?php

declare(strict_types=1);

namespace App\Game\Hzmj;

use App\Game\Contract\ResumeBuilderInterface;
use App\Game\Hzmj\Rule\HzmjRules;
use App\Game\Platform\DismissVoteService;
use App\Model\Game\GameRound;
use App\Service\Game\GamePlayCardService;
use App\Service\Game\GameRoundService;
use App\Service\Game\RoomMemberService;
use App\Service\Game\RoomService;
use App\Service\Member\MemberService;
use function Hyperf\Support\make;

/**
 * 红中麻将断线重连牌局快照
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
        $state = RoundState::decode($round->extra ?? null);
        $phase = (string) ($state['phase'] ?? HzmjConsts::PHASE_PLAYING);

        $members = $roomMemberService->getActiveInRoom($roomId, 'player_id,seat_no,fd');
        $seatByPid = [];
        $publicSeats = [];
        $pids = [];
        foreach ($members as $m) {
            $pid = (int) ($m['player_id'] ?? 0);
            $seat = (int) ($m['seat_no'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $seatByPid[$pid] = $seat;
            $pids[] = $pid;
        }
        $profiles = [];
        if ($pids !== []) {
            $memberSvc = make(MemberService::class);
            foreach ($memberSvc->getAll(['id' => $pids], 'id,avatar,nickname,realname,points') as $profile) {
                $mid = (int) ($profile['id'] ?? 0);
                if ($mid > 0) {
                    $profiles[$mid] = $profile;
                }
            }
            foreach ($seatByPid as $pid => $seat) {
                $profile = $profiles[$pid] ?? [];
                $relAvatar = (string) ($profile['avatar'] ?? '');
                $pts = (float) ($profile['points'] ?? 0);
                $publicSeats[] = [
                    'seat_no'    => $seat,
                    'seatNo'     => $seat,
                    'player_id'  => $pid,
                    'playerId'   => $pid,
                    'nickname'   => (string) ($profile['nickname'] ?? $profile['realname'] ?? ('玩家' . $pid)),
                    'avatar'     => $relAvatar,
                    'avatar_url' => $memberSvc->buildAvatarUrl($relAvatar),
                    'points'     => $pts,
                    'gold'       => $pts,
                ];
            }
        }
        if (! isset($seatByPid[$playerId])) {
            return null;
        }
        $mySeat = $seatByPid[$playerId];

        // 抢庄阶段：尚无庄家 / 未发牌
        if ($phase === HzmjConsts::PHASE_GRAB && (int) ($round->master_id ?? 0) <= 0) {
            $grabsRaw = is_array($state['grabs'] ?? null) ? $state['grabs'] : [];
            $grabs = [];
            foreach ($grabsRaw as $g) {
                if (! is_array($g)) {
                    continue;
                }
                $grabs[] = [
                    'seat'      => (int) ($g['seat'] ?? 0),
                    'seatNo'    => (int) ($g['seat'] ?? 0),
                    'player_id' => (int) ($g['player_id'] ?? 0),
                    'playerId'  => (int) ($g['player_id'] ?? 0),
                    'multiple'  => (int) ($g['multiple'] ?? 0),
                ];
            }
            $payload = [
                'callback'      => CallBack::GRAB,
                'roundId'       => $roundId,
                'phase'         => HzmjConsts::PHASE_GRAB,
                'mySeat'        => $mySeat,
                'grabs'         => $grabs,
                'grabbed'       => count($grabs),
                'need'          => count($publicSeats),
                'players'       => $publicSeats,
                'seats'         => $publicSeats,
                'luckyNum'      => (int) ($state['luckyNum'] ?? 2),
                'maxPlayers'    => (int) ($state['maxPlayers'] ?? count($publicSeats)),
                'grabMultiples' => HzmjConsts::GRAB_MULTIPLES,
                'countdown'     => HzmjConsts::GRAB_TIMEOUT,
                'df'            => (float) ($state['df'] ?? 1),
                '_fromResume'   => true,
            ];

            return $this->attachDismiss($roomId, $payload);
        }

        if ((int) ($round->master_id ?? 0) <= 0) {
            return null;
        }

        $seats = [];
        $myTiles = [];
        foreach ($seatByPid as $pid => $seat) {
            $hand = HzmjRules::toIntTiles($gamePlayCardService->getLatestHand($roundId, $pid));
            $seats[] = [
                'seatNo'    => $seat,
                'tileCount' => count($hand),
                'cardsCount'=> count($hand),
            ];
            if ($pid === $playerId) {
                $myTiles = HzmjRules::sortTiles($hand, HzmjConsts::KING_TILE);
            }
        }

        $waitOps = is_array($state['waitOps'] ?? null) ? $state['waitOps'] : [];
        $myWaitOps = array_values(array_map('intval', is_array($waitOps[(string) $mySeat] ?? null) ? $waitOps[(string) $mySeat] : []));

        $room = $roomService->getOne(['id' => $roomId], 'id,played_count,total_play_count');
        $wall = array_values(array_map('intval', is_array($state['tileWall'] ?? null) ? $state['tileWall'] : []));

        $payload = [
            'callback'       => CallBack::START_GAME,
            'roundId'        => $roundId,
            'mySeat'         => $mySeat,
            'myTiles'        => $myTiles,
            'seats'          => $seats,
            'discards'       => $state['discards'] ?? [],
            'ups'            => $state['ups'] ?? [],
            'dealerSeat'     => (int) ($state['dealerSeat'] ?? 0),
            'curSeat'        => (int) ($state['curSeat'] ?? 0),
            'leftTileNum'    => count($wall),
            'kingTiles'      => array_map('intval', is_array($state['kingTiles'] ?? null) ? $state['kingTiles'] : [HzmjConsts::KING_TILE]),
            'pendingDiscard' => $state['pendingDiscard'] ?? null,
            'myWaitOps'      => $myWaitOps,
            'phase'          => (string) ($state['phase'] ?? HzmjConsts::PHASE_PLAYING),
            'luckyNum'       => (int) ($state['luckyNum'] ?? 2),
            'playedCount'    => (int) ($room?->played_count ?? 0),
            'totalCount'     => (int) ($room?->total_play_count ?? 0),
            'maxPlayers'     => count($publicSeats) > 0 ? count($publicSeats) : (int) ($state['maxPlayers'] ?? 4),
            'playerNum'      => count($publicSeats) > 0 ? count($publicSeats) : (int) ($state['maxPlayers'] ?? 4),
            'trusted'        => $roomMemberService->isTrusted($roomId, $playerId),
            'diceList'       => [],
            'bankBeishu'     => max(1, (int) ($state['bankBeishu'] ?? $round->multiple ?? 1)),
            'grabMultiple'   => max(1, (int) ($state['bankBeishu'] ?? $round->multiple ?? 1)),
            'df'             => (float) ($state['df'] ?? 1),
            'players'        => $publicSeats,
            '_fromResume'    => true,
        ];

        return $this->attachDismiss($roomId, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function attachDismiss(int $roomId, array $payload): array
    {
        $dismissSvc = make(DismissVoteService::class);
        $dismiss = $dismissSvc->get($roomId);
        if ($dismiss !== null) {
            $payload['applyCloseFromSeat'] = (int) $dismiss['fromSeat'];
            $payload['applyCloseFrom'] = (int) $dismiss['fromSeat'];
            $payload['applyCloseStateList'] = array_values(array_map('intval', $dismiss['states']));
            $payload['applyCloseLeftTime'] = $dismissSvc->leftTime($dismiss);
        }

        return $payload;
    }
}
