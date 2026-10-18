<?php

declare(strict_types=1);

namespace App\Game\Pdk\Rule;

use App\Game\Pdk\PdkConsts;

/**
 * 跑得快加倍规则：发牌后三人可同时选 0/2，全选完进入出牌
 */
final class DoubleRule
{
    public static function isAllowedScore(int $score): bool
    {
        return in_array($score, PdkConsts::DOUBLE_SCORES, true);
    }

    /** 协议分 → 结算系数：0→1，2→2 */
    public static function settleFactor(int $score): int
    {
        return $score > 0 ? $score : 1;
    }

    /**
     * 尚未加倍的座位（0..MAX_PLAYERS-1）
     *
     * @param array<string, int> $doubles seatNo => score
     * @return list<int>
     */
    public static function pendingSeats(array $doubles): array
    {
        $pending = [];
        for ($seat = 0; $seat < PdkConsts::MAX_PLAYERS; $seat++) {
            if (! isset($doubles[(string) $seat])) {
                $pending[] = $seat;
            }
        }

        return $pending;
    }

    /** 三家是否都已选择 */
    public static function isComplete(array $doubles): bool
    {
        return self::pendingSeats($doubles) === [];
    }

    /**
     * 兼容旧逻辑：返回下一个未加倍座位；全选完返回 null
     *
     * @param array<string, int> $doubles seatNo => score
     */
    public static function nextTurn(int $firstSeat, array $doubles): ?int
    {
        $pending = self::pendingSeats($doubles);
        if ($pending === []) {
            return null;
        }
        $start = max(0, $firstSeat);
        for ($i = 0; $i < PdkConsts::MAX_PLAYERS; $i++) {
            $seat = ($start + $i) % PdkConsts::MAX_PLAYERS;
            if (in_array($seat, $pending, true)) {
                return $seat;
            }
        }

        return $pending[0];
    }
}
