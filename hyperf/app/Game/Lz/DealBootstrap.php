<?php

declare(strict_types=1);

namespace App\Game\Lz;

use App\Game\Support\GameRecordHooks;
use App\Model\Game\GameRoom;
use App\Model\Game\GameRound;
use App\Service\Game\GamePlayCardService;
use App\Service\Game\GameRoundService;
use App\Service\Game\RoomMemberService;
use App\Service\Game\RoomService;
use function Hyperf\Support\make;

/**
 * 发牌落库 + 座位查找（方片3 / 地主）
 */
final class DealBootstrap
{
    /**
     * @param list<array<string,mixed>> $members
     * @return array{
     *   players: list<array{seat_no:int,player_id:int,nickname:string,avatar:string,fd:int,cards:list<string>}>,
     *   remain: list<string>,
     *   diamond3Seat: int
     * }
     */
    public static function dealToMembers(array $members): array
    {
        $deal = Deal::deal();
        $players = [];
        $diamond3Seat = 0;
        foreach ($members as $m) {
            $seat = (int) $m['seat_no'];
            $hand = $deal['hands'][$seat] ?? [];
            sort($hand);
            if (in_array(LzConsts::DIAMOND_THREE, $hand, true)) {
                $diamond3Seat = $seat;
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
        $remain = $deal['remain'];
        sort($remain);

        return [
            'players'      => $players,
            'remain'       => $remain,
            'diamond3Seat' => $diamond3Seat,
        ];
    }

    /**
     * 抢庄模式：先发 17 张手牌，底牌暂存，地主未定
     *
     * @param list<array{seat_no:int,player_id:int,nickname:string,avatar:string,fd:int,cards:list<string>}> $players
     * @param list<string> $remain
     * @param array<string,mixed> $state
     * @return list<array{seat_no:int,player_id:int,nickname:string,avatar:string,fd:int,cards:list<string>}>
     */
    public static function persistHandsForGrab(
        int $roomId,
        int $roundId,
        array $players,
        array $remain,
        array $state,
    ): array {
        $state['phase'] = LzConsts::PHASE_GRAB;
        $state['remain'] = $remain;
        $state['landlordSeat'] = -1;

        make(GameRoundService::class)->update($roundId, [
            'status'    => GameRound::STATUS_PLAYING,
            'master_id' => 0,
            'extra'     => RoundState::encode($state),
        ]);

        $roomService = make(RoomService::class);
        $room = GameRecordHooks::loadRoomMeta($roomId);
        if ($room && (int) $room->status !== GameRoom::STATUS_PLAYING) {
            $roomService->update($roomId, ['status' => GameRoom::STATUS_PLAYING]);
        }

        make(RoomMemberService::class)->markPlayingForRoom($roomId);
        make(GamePlayCardService::class)->recordDeal($roundId, $players);

        self::beginGameRecord($room, $roundId, $players, $remain, $state, -1);

        return $players;
    }

    /**
     * 叫地主结束后：底牌归地主，进入加倍阶段（尚未出牌）
     *
     * @param array<string,mixed> $state
     * @return array{
     *   players: list<array{seat_no:int,player_id:int,nickname:string,avatar:string,fd:int,cards:list<string>}>,
     *   remain: list<string>,
     *   state: array<string,mixed>
     * }
     */
    public static function awardLandlordAndWaitDouble(
        int $roomId,
        int $roundId,
        array $state,
        int $landlordPid,
        int $landlordSeat,
        int $landlordMultiple,
    ): array {
        return self::awardLandlord($roomId, $roundId, $state, $landlordPid, $landlordSeat, $landlordMultiple, LzConsts::PHASE_DOUBLE);
    }

    /**
     * 叫地主结束后：底牌归地主并直接进入出牌（跳过加倍时用）
     *
     * @param array<string,mixed> $state
     * @return array{
     *   players: list<array{seat_no:int,player_id:int,nickname:string,avatar:string,fd:int,cards:list<string>}>,
     *   remain: list<string>
     * }
     */
    public static function awardLandlordAndPlay(
        int $roomId,
        int $roundId,
        array $state,
        int $landlordPid,
        int $landlordSeat,
        int $landlordMultiple,
    ): array {
        $out = self::awardLandlord($roomId, $roundId, $state, $landlordPid, $landlordSeat, $landlordMultiple, LzConsts::PHASE_PLAY);

        return [
            'players' => $out['players'],
            'remain'  => $out['remain'],
        ];
    }

    /**
     * 农民加倍完成后：不重发牌，仅切到出牌阶段
     *
     * @param array<string,mixed> $state
     * @return array{
     *   players: list<array{seat_no:int,player_id:int,nickname:string,avatar:string,fd:int,cards:list<string>}>,
     *   remain: list<string>
     * }
     */
    public static function beginPlayAfterDouble(
        int $roomId,
        int $roundId,
        array $state,
        int $landlordPid,
    ): array {
        $remain = array_values($state['remain'] ?? []);
        $members = make(RoomMemberService::class)->getActiveInRoom($roomId);
        $cardService = make(GamePlayCardService::class);

        $players = [];
        foreach ($members as $m) {
            $pid = (int) ($m['player_id'] ?? 0);
            $seat = (int) ($m['seat_no'] ?? 0);
            $players[] = [
                'seat_no'   => $seat,
                'player_id' => $pid,
                'nickname'  => (string) ($m['nickname'] ?? ''),
                'avatar'    => (string) ($m['avatar'] ?? ''),
                'fd'        => (int) ($m['fd'] ?? 0),
                'cards'     => $cardService->getLatestHand($roundId, $pid),
            ];
        }

        $state['phase'] = LzConsts::PHASE_PLAY;
        $state['remain'] = $remain;
        make(GameRoundService::class)->update($roundId, [
            'status'    => GameRound::STATUS_PLAYING,
            'master_id' => $landlordPid,
            'extra'     => RoundState::encode($state),
        ]);

        return [
            'players' => $players,
            'remain'  => $remain,
        ];
    }

    /**
     * @param array<string,mixed> $state
     * @return array{
     *   players: list<array{seat_no:int,player_id:int,nickname:string,avatar:string,fd:int,cards:list<string>}>,
     *   remain: list<string>,
     *   state: array<string,mixed>
     * }
     */
    private static function awardLandlord(
        int $roomId,
        int $roundId,
        array $state,
        int $landlordPid,
        int $landlordSeat,
        int $landlordMultiple,
        string $phase,
    ): array {
        $remain = array_values($state['remain'] ?? []);
        $members = make(RoomMemberService::class)->getActiveInRoom($roomId);
        $cardService = make(GamePlayCardService::class);

        $players = [];
        foreach ($members as $m) {
            $pid = (int) ($m['player_id'] ?? 0);
            $seat = (int) ($m['seat_no'] ?? 0);
            $hand = $cardService->getLatestHand($roundId, $pid);
            if ($seat === $landlordSeat) {
                $hand = array_merge($hand, $remain);
                sort($hand);
                $cardService->setLandlordHand($roundId, $landlordPid, $hand);
            }
            $players[] = [
                'seat_no'   => $seat,
                'player_id' => $pid,
                'nickname'  => (string) ($m['nickname'] ?? ''),
                'avatar'    => (string) ($m['avatar'] ?? ''),
                'fd'        => (int) ($m['fd'] ?? 0),
                'cards'     => $hand,
            ];
        }

        $state['phase'] = $phase;
        $state['remain'] = $remain;
        $state['landlordSeat'] = $landlordSeat;
        $state['landlordMultiple'] = max(1, $landlordMultiple);
        // 叫抢明细已在 land_lord_bid；定庄后清空以免 extra JSON 过大
        $state['grabs'] = [];
        if ($phase === LzConsts::PHASE_DOUBLE) {
            $state['farmerDoubles'] = [];
            // 三家同时加倍，无轮次；截止时间供超时与剩余秒数
            $state['doubleTurn'] = -1;
            $state['doubleDeadlineAt'] = time() + LzConsts::DOUBLE_TIMEOUT;
            $state['bombCount'] = 0;
        }

        make(GameRoundService::class)->update($roundId, [
            'status'    => GameRound::STATUS_PLAYING,
            'master_id' => $landlordPid,
            'extra'     => RoundState::encode($state),
        ]);

        return [
            'players' => $players,
            'remain'  => $remain,
            'state'   => $state,
        ];
    }

    /**
     * 发牌后落库：局 PLAYING、手牌、房间状态、成员 playing；底牌归地主
     *
     * @param list<array{seat_no:int,player_id:int,nickname:string,avatar:string,fd:int,cards:list<string>}> $players
     * @param list<string> $remain
     * @param array<string,mixed> $state RoundState
     * @return list<array{seat_no:int,player_id:int,nickname:string,avatar:string,fd:int,cards:list<string>}>
     */
    public static function persistDeal(
        int $roomId,
        int $roundId,
        array $players,
        array $remain,
        array $state,
        int $landlordPid,
        int $landlordSeat,
    ): array {
        $state['phase'] = LzConsts::PHASE_PLAY;
        $state['remain'] = $remain;
        $state['landlordSeat'] = $landlordSeat;

        make(GameRoundService::class)->update($roundId, [
            'status'    => GameRound::STATUS_PLAYING,
            'master_id' => $landlordPid,
            'extra'     => RoundState::encode($state),
        ]);

        $roomService = make(RoomService::class);
        $room = GameRecordHooks::loadRoomMeta($roomId);
        if ($room && (int) $room->status !== GameRoom::STATUS_PLAYING) {
            $roomService->update($roomId, ['status' => GameRoom::STATUS_PLAYING]);
        }

        make(RoomMemberService::class)->markPlayingForRoom($roomId);
        make(GamePlayCardService::class)->recordDeal($roundId, $players);

        self::beginGameRecord($room, $roundId, $players, $remain, $state, $landlordSeat);

        foreach ($players as &$p) {
            if ((int) $p['seat_no'] === $landlordSeat) {
                $full = array_merge($p['cards'], $remain);
                sort($full);
                $p['cards'] = $full;
                make(GamePlayCardService::class)->setLandlordHand($roundId, $landlordPid, $full);
            }
        }
        unset($p);

        return $players;
    }

    /**
     * @param object|null $room
     * @param list<array<string,mixed>> $players
     * @param list<string> $remain
     * @param array<string,mixed> $state
     */
    private static function beginGameRecord(
        ?object $room,
        int $roundId,
        array $players,
        array $remain,
        array $state,
        int $landlordSeat,
    ): void {
        if ($room === null || $roundId <= 0) {
            return;
        }
        try {
            $seats = [];
            $recordPlayers = [];
            foreach ($players as $p) {
                $seat = (int) ($p['seat_no'] ?? 0);
                $tiles = array_values($p['cards'] ?? []);
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
                    'isBanker' => $landlordSeat >= 0 && $seat === $landlordSeat,
                    'tiles'    => $tiles,
                ];
            }

            $ext = [
                'remain'           => array_values($remain),
                'askMode'          => (int) ($state['askMode'] ?? LzConsts::ASK_GRAB),
                'landlordMultiple' => max(1, (int) ($state['landlordMultiple'] ?? 1)),
                'wildCode'         => LzConsts::WILD_CARD,
            ];
            if (isset($state['grabTurn'])) {
                $ext['grabTurn'] = (int) $state['grabTurn'];
            }

            GameRecordHooks::beginFromRoom($room, $roundId, $recordPlayers, [
                'seats'      => $seats,
                'dealerSeat' => $landlordSeat >= 0 ? $landlordSeat : -1,
                'ext'        => $ext,
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
