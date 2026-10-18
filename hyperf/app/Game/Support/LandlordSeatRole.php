<?php

declare(strict_types=1);

namespace App\Game\Support;

/**
 * 三人斗类座位相对地主角色：下家 / 上家 / 地主
 */
final class LandlordSeatRole
{
    public const NEXT = 'next';

    public const PREV = 'prev';

    public const LANDLORD = 'landlord';

    public static function of(int $mySeat, int $landlordSeat, int $maxPlayers = 3): string
    {
        $n = max(2, $maxPlayers);
        $mySeat = (($mySeat % $n) + $n) % $n;
        $landlordSeat = (($landlordSeat % $n) + $n) % $n;
        if ($mySeat === $landlordSeat) {
            return self::LANDLORD;
        }
        if ($mySeat === ($landlordSeat + 1) % $n) {
            return self::NEXT;
        }

        return self::PREV;
    }
}
