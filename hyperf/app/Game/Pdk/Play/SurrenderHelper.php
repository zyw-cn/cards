<?php

declare(strict_types=1);

namespace App\Game\Pdk\Play;

/**
 * 跑得快认输：炸弹输光金币后自动认输，轮次跳过认输座位
 */
final class SurrenderHelper
{
    /**
     * @param list<int> $seats
     * @return list<int>
     */
    public static function normalizeSeats(array $seats): array
    {
        $out = [];
        foreach ($seats as $seat) {
            $s = (int) $seat;
            if ($s >= 0 && ! in_array($s, $out, true)) {
                $out[] = $s;
            }
        }

        return $out;
    }

    /**
     * 炸弹结算后：非出牌者金币为 0 则自动认输
     *
     * @param array<string, mixed> $roundState
     * @param list<array<string, mixed>> $rewards
     * @return array<string, mixed>
     */
    public static function markBrokeAfterBomb(array $roundState, array $rewards): array
    {
        $surrendered = self::normalizeSeats($roundState['surrendered'] ?? []);
        foreach ($rewards as $r) {
            if (! is_array($r)) {
                continue;
            }
            if (! empty($r['isBomber'])) {
                continue;
            }
            $seat = (int) ($r['seatNo'] ?? -1);
            $points = (float) ($r['points'] ?? 0);
            if ($seat >= 0 && $points <= 0) {
                $surrendered[] = $seat;
            }
        }
        $roundState['surrendered'] = self::normalizeSeats($surrendered);

        return $roundState;
    }

    /**
     * @param list<int> $allSeats
     * @param list<int> $surrendered
     * @return list<int>
     */
    public static function activeSeats(array $allSeats, array $surrendered): array
    {
        $surrendered = self::normalizeSeats($surrendered);

        return array_values(array_filter(
            self::normalizeSeats($allSeats),
            static fn (int $seat): bool => ! in_array($seat, $surrendered, true)
        ));
    }

    public static function isSurrendered(int $seat, array $surrendered): bool
    {
        return in_array($seat, self::normalizeSeats($surrendered), true);
    }

    /** 轮次落在未认输座位 */
    public static function advanceToActive(int $seat, array $surrendered, int $maxPlayers): int
    {
        $surrendered = self::normalizeSeats($surrendered);
        if ($surrendered === [] || $maxPlayers <= 0) {
            return $seat;
        }
        $cur = $seat;
        for ($i = 0; $i < $maxPlayers; $i++) {
            if (! self::isSurrendered($cur, $surrendered)) {
                return $cur;
            }
            $cur = ($cur + 1) % $maxPlayers;
        }

        return $seat;
    }
}
