<?php

declare(strict_types=1);

namespace App\Game\Support;

use App\Service\Game\GameRecordService;
use App\Service\Game\RoomService;
use function Hyperf\Support\make;

/**
 * 各玩法录像挂接辅助（begin 参数组装 / 安全调用）
 */
final class GameRecordHooks
{
    /**
     * @param object $room 需含 id,room_num,member_id,played_count,total_play_count,rule,game_type
     * @param list<array{memberId:int,seatNo:int,nickname?:string,avatar?:string,isBanker?:bool,tiles?:list<mixed>}> $players
     * @param array<string, mixed> $init
     */
    public static function beginFromRoom(object $room, int $roundId, array $players, array $init): int
    {
        $ruleRaw = $room->rule ?? null;
        $ruleArr = [];
        if (is_string($ruleRaw) && $ruleRaw !== '') {
            $decoded = json_decode($ruleRaw, true);
            $ruleArr = is_array($decoded) ? $decoded : [];
        } elseif (is_array($ruleRaw)) {
            $ruleArr = $ruleRaw;
        }

        $unit = make(RoomService::class)->resolveBottomPoints(
            is_string($ruleRaw) ? $ruleRaw : null
        );
        $played = (int) ($room->played_count ?? 0);
        $total = (int) ($room->total_play_count ?? 0);

        return make(GameRecordService::class)->begin([
            'gameType'  => (int) ($room->game_type ?? 0),
            'roomId'    => (int) ($room->id ?? 0),
            'roundId'   => $roundId,
            'roomNum'   => (string) ($room->room_num ?? ''),
            'ownerId'   => (int) ($room->member_id ?? 0),
            'curRound'  => $played + 1,
            'maxRound'  => max(1, $total),
            'playerNum' => count($players),
            'unit'      => $unit,
            'rule'      => $ruleArr,
            'init'      => $init,
            'players'   => $players,
        ]);
    }

    /**
     * @param list<int|string> $tiles
     * @param array<string, mixed> $ext
     */
    public static function append(int $roundId, string $op, int $seat = -1, int $fromSeat = -1, array $tiles = [], array $ext = []): void
    {
        if ($roundId <= 0 || $op === '') {
            return;
        }
        try {
            make(GameRecordService::class)->appendOp($roundId, $op, $seat, $fromSeat, $tiles, $ext);
        } catch (\Throwable) {
            // ignore
        }
    }

    /**
     * @param array<string, mixed> $result
     * @param list<array{memberId:int,score?:float|int|string,isWinner?:bool,isBanker?:bool}> $playerScores
     */
    public static function finish(int $roundId, array $result, array $playerScores = []): void
    {
        if ($roundId <= 0) {
            return;
        }
        try {
            make(GameRecordService::class)->finish($roundId, $result, $playerScores);
        } catch (\Throwable) {
            // ignore
        }
    }

    /**
     * 从房间行补齐录像所需字段（各 DealBootstrap 原先只取 id,status）
     *
     * @return object|null
     */
    public static function loadRoomMeta(int $roomId): ?object
    {
        if ($roomId <= 0) {
            return null;
        }

        return make(RoomService::class)->getOne(
            ['id' => $roomId],
            'id,status,room_num,member_id,played_count,total_play_count,rule,game_type'
        );
    }
}
