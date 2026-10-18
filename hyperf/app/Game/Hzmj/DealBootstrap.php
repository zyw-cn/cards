<?php

declare(strict_types=1);

namespace App\Game\Hzmj;

use App\Constants\GameType;
use App\Game\Hzmj\Rule\HzmjRules;
use App\Game\Support\GameRecordHooks;
use App\Model\Game\GameRoom;
use App\Model\Game\GameRound;
use App\Service\Game\GamePlayCardService;
use App\Service\Game\GameRoundService;
use App\Service\Game\RoomMemberService;
use App\Service\Game\RoomService;
use function Hyperf\Support\make;

/**
 * 发牌落库 + 庄家定位
 */
final class DealBootstrap
{
    /**
     * @param list<array<string,mixed>> $members
     * @return array{
     *   players: list<array{seat_no:int,player_id:int,nickname:string,avatar:string,avatar_url:string,fd:int,points:float,cards:list<int>}>,
     *   wall: list<int>,
     *   dealerSeat: int
     * }
     */
    public static function dealToMembers(array $members, int $dealerSeat): array
    {
        $seats = [];
        foreach ($members as $m) {
            $seats[] = (int) ($m['seat_no'] ?? 0);
        }
        sort($seats);
        $playerNum = count($seats);
        if ($playerNum < HzmjConsts::MIN_PLAYERS) {
            $playerNum = HzmjConsts::MIN_PLAYERS;
        }

        // 座位可能不连续时：按排序后的座位映射到手牌下标 0..n-1
        $deal = Deal::deal($playerNum, self::dealerIndexAmongSeats($seats, $dealerSeat));
        $seatIndex = [];
        foreach ($seats as $i => $seat) {
            $seatIndex[$seat] = $i;
        }

        $players = [];
        foreach ($members as $m) {
            $seat = (int) $m['seat_no'];
            $idx = $seatIndex[$seat] ?? $seat;
            $hand = HzmjRules::sortTiles($deal['hands'][$idx] ?? []);
            $players[] = [
                'seat_no'    => $seat,
                'player_id'  => (int) $m['player_id'],
                'nickname'   => (string) ($m['nickname'] ?? ''),
                'avatar'     => (string) ($m['avatar'] ?? ''),
                'avatar_url' => (string) ($m['avatar_url'] ?? ''),
                'fd'         => (int) ($m['fd'] ?? 0),
                'points'     => (float) ($m['points'] ?? 0),
                'cards'      => $hand,
            ];
        }

        return [
            'players'    => $players,
            'wall'       => $deal['wall'],
            'dealerSeat' => $dealerSeat,
        ];
    }

    /**
     * @param list<int> $seats
     */
    private static function dealerIndexAmongSeats(array $seats, int $dealerSeat): int
    {
        foreach ($seats as $i => $seat) {
            if ((int) $seat === $dealerSeat) {
                return (int) $i;
            }
        }

        return 0;
    }

    /**
     * @param list<array{seat_no:int,player_id:int,nickname:string,avatar:string,fd:int,cards:list<int>}> $players
     * @param array<string,mixed> $state
     * @param list<int> $wall
     * @return list<array{seat_no:int,player_id:int,nickname:string,avatar:string,fd:int,cards:list<int>}>
     */
    public static function persistDeal(
        int $roomId,
        int $roundId,
        array $players,
        array $state,
        int $dealerPid,
        int $dealerSeat,
        array $wall,
    ): array {
        $maxPlayers = max(count($players), (int) ($state['maxPlayers'] ?? HzmjConsts::MAX_PLAYERS));
        $discards = [];
        $ups = [];
        foreach ($players as $p) {
            $key = (string) (int) $p['seat_no'];
            $discards[$key] = [];
            $ups[$key] = [];
        }

        $state['phase'] = HzmjConsts::PHASE_PLAYING;
        $state['dealerSeat'] = $dealerSeat;
        $state['curSeat'] = $dealerSeat;
        $state['kingTiles'] = [HzmjConsts::KING_TILE];
        $state['tileWall'] = array_values($wall);
        $state['discards'] = $discards;
        $state['ups'] = $ups;
        $state['pendingDiscard'] = null;
        $state['waitOps'] = [];
        $state['maxPlayers'] = $maxPlayers;

        // 庄家起手可能有自摸/暗杠提示
        $dealerHand = [];
        foreach ($players as $p) {
            if ((int) $p['seat_no'] === $dealerSeat) {
                $dealerHand = array_map('intval', $p['cards'] ?? []);
                break;
            }
        }
        $drawOps = HzmjRules::getDrawNotifyOps($dealerHand, [], $state['kingTiles']);
        if ($drawOps !== []) {
            $state['waitOps'] = [
                (string) $dealerSeat => array_values(array_merge($drawOps, [HzmjConsts::SHOW_OP_PASS])),
            ];
        }

        make(GameRoundService::class)->update($roundId, [
            'status'    => GameRound::STATUS_PLAYING,
            'master_id' => $dealerPid,
            'extra'     => RoundState::encode($state),
        ]);

        $roomService = make(RoomService::class);
        $room = $roomService->getOne(
            ['id' => $roomId],
            'id,status,room_num,member_id,played_count,total_play_count,rule,game_type'
        );
        if ($room && (int) $room->status !== GameRoom::STATUS_PLAYING) {
            $roomService->update($roomId, ['status' => GameRoom::STATUS_PLAYING]);
        }

        make(RoomMemberService::class)->markPlayingForRoom($roomId);

        $forRecord = [];
        foreach ($players as $p) {
            $forRecord[] = [
                'player_id' => (int) $p['player_id'],
                'cards'     => HzmjRules::toStringTiles(array_map('intval', $p['cards'] ?? [])),
            ];
        }
        make(GamePlayCardService::class)->recordDeal($roundId, $forRecord);

        self::beginGameRecord($room, $roundId, $players, $state, $dealerSeat, $wall);

        return $players;
    }

    /**
     * @param object|null $room
     * @param list<array<string,mixed>> $players
     * @param array<string,mixed> $state
     * @param list<int> $wall
     */
    private static function beginGameRecord(
        ?object $room,
        int $roundId,
        array $players,
        array $state,
        int $dealerSeat,
        array $wall,
    ): void {
        if ($room === null || $roundId <= 0) {
            return;
        }
        try {
            $seats = [];
            $recordPlayers = [];
            foreach ($players as $p) {
                $seat = (int) ($p['seat_no'] ?? 0);
                $tiles = array_map('intval', $p['cards'] ?? []);
                $seats[] = [
                    'seat'     => $seat,
                    'memberId' => (int) ($p['player_id'] ?? 0),
                    'nickname' => (string) ($p['nickname'] ?? ''),
                    'avatar'   => (string) ($p['avatar'] ?? ''),
                    'tiles'    => $tiles,
                ];
                $recordPlayers[] = [
                    'memberId' => (int) ($p['player_id'] ?? 0),
                    'seatNo'   => $seat,
                    'nickname' => (string) ($p['nickname'] ?? ''),
                    'avatar'   => (string) ($p['avatar'] ?? ''),
                    'isBanker' => $seat === $dealerSeat,
                ];
            }

            // ensure game_type for hooks
            if (! isset($room->game_type)) {
                $room->game_type = GameType::HZMJ;
            }

            GameRecordHooks::beginFromRoom($room, $roundId, $recordPlayers, [
                'seats'      => $seats,
                'dealerSeat' => $dealerSeat,
                'ext'        => [
                    'kingTiles' => array_map('intval', is_array($state['kingTiles'] ?? null) ? $state['kingTiles'] : [HzmjConsts::KING_TILE]),
                    'diceList'  => is_array($state['diceList'] ?? null) ? array_values($state['diceList']) : [],
                    'tileWall'  => array_values(array_map('intval', $wall)),
                    'luckyNum'  => (int) ($state['luckyNum'] ?? 2),
                ],
            ]);
        } catch (\Throwable) {
            // 录像失败不影响开局
        }
    }

    /**
     * @param list<array{seat_no:int,player_id:int}> $players
     */
    public static function pidOfSeat(array $players, int $seat): int
    {
        foreach ($players as $p) {
            if ((int) ($p['seat_no'] ?? -1) === $seat) {
                return (int) ($p['player_id'] ?? 0);
            }
        }

        return 0;
    }
}
