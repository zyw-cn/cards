<?php

declare(strict_types=1);

namespace App\Game\Pdk;

use App\Game\Support\GameRecordHooks;
use App\Model\Game\GameRoom;
use App\Model\Game\GameRound;
use App\Service\Game\GamePlayCardService;
use App\Service\Game\GameRoundService;
use App\Service\Game\RoomMemberService;
use App\Service\Game\RoomService;
use function Hyperf\Support\make;

/**
 * 发牌落库 + 红桃 3 座位定位
 */
final class DealBootstrap
{
    /**
     * @param list<array<string,mixed>> $members
     * @return array{
     *   players: list<array{seat_no:int,player_id:int,nickname:string,avatar:string,avatar_url:string,fd:int,points:float,cards:list<string>}>,
     *   firstSeat: int
     * }
     */
    public static function dealToMembers(array $members): array
    {
        $deal = Deal::deal();
        $players = [];
        $firstSeat = 0;
        foreach ($members as $m) {
            $seat = (int) $m['seat_no'];
            $hand = $deal['hands'][$seat] ?? [];
            sort($hand);
            if (in_array(PdkConsts::SPADE_THREE, $hand, true)) {
                $firstSeat = $seat;
            }
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
            'players'   => $players,
            'firstSeat' => $firstSeat,
        ];
    }

    /**
     * @param list<array{seat_no:int,player_id:int,nickname:string,avatar:string,fd:int,cards:list<string>}> $players
     * @param array<string,mixed> $state
     * @return list<array{seat_no:int,player_id:int,nickname:string,avatar:string,fd:int,cards:list<string>}>
     */
    public static function persistDeal(
        int $roomId,
        int $roundId,
        array $players,
        array $state,
        int $firstPid,
        int $firstSeat,
    ): array {
        $state['phase'] = PdkConsts::PHASE_PLAY;
        $state['firstSeat'] = $firstSeat;
        $state['bombCount'] = 0;
        $state['bombs'] = [];
        $state['spade3Played'] = false;

        make(GameRoundService::class)->update($roundId, [
            'status'    => GameRound::STATUS_PLAYING,
            'master_id' => $firstPid,
            'extra'     => RoundState::encode($state),
        ]);

        $roomService = make(RoomService::class);
        $room = $roomService->getOne(['id' => $roomId], 'id,status');
        if ($room && (int) $room->status !== GameRoom::STATUS_PLAYING) {
            $roomService->update($roomId, ['status' => GameRoom::STATUS_PLAYING]);
        }

        make(RoomMemberService::class)->markPlayingForRoom($roomId);
        make(GamePlayCardService::class)->recordDeal($roundId, $players);

        self::beginGameRecord($roomId, $roundId, $players, $firstSeat);

        return $players;
    }

    /**
     * @param list<array{seat_no:int,player_id:int,nickname?:string,avatar?:string,cards?:list<string>}> $players
     */
    private static function beginGameRecord(int $roomId, int $roundId, array $players, int $firstSeat): void
    {
        try {
            $meta = GameRecordHooks::loadRoomMeta($roomId);
            if ($meta === null || $roundId <= 0) {
                return;
            }
            $seats = [];
            $recordPlayers = [];
            foreach ($players as $p) {
                $seat = (int) ($p['seat_no'] ?? 0);
                $tiles = array_values(is_array($p['cards'] ?? null) ? $p['cards'] : []);
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
                    'isBanker' => $seat === $firstSeat,
                ];
            }
            GameRecordHooks::beginFromRoom($meta, $roundId, $recordPlayers, [
                'seats'      => $seats,
                'dealerSeat' => $firstSeat,
                'ext'        => [
                    'firstSeat' => $firstSeat,
                    'spade3'    => PdkConsts::SPADE_THREE,
                ],
            ]);
        } catch (\Throwable) {
            // 录像失败不影响牌局
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
