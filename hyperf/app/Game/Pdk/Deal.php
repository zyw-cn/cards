<?php

declare(strict_types=1);

namespace App\Game\Pdk;

/**
 * 跑得快发牌（纯函数，无 IO）
 *
 * 48 张：去掉方片 A、红桃 2、梅花 2、方片 2（留黑桃 2），三人各 16。
 */
final class Deal
{
    /**
     * @return list<string>
     */
    public static function buildDeck(): array
    {
        $deck = [];
        foreach (['A', 'B', 'C', 'D'] as $suit) {
            for ($point = 3; $point <= 15; $point++) {
                $deck[] = $suit . $point;
            }
        }
        $remove = array_flip(PdkConsts::REMOVED_CARDS);
        $deck = array_values(array_filter(
            $deck,
            static fn (string $c): bool => ! isset($remove[$c])
        ));
        shuffle($deck);

        return $deck;
    }

    /**
     * @return array{hands: array<int, list<string>>}
     */
    public static function deal(): array
    {
        $deck = self::buildDeck();
        $size = PdkConsts::HAND_SIZE;

        return [
            'hands' => [
                0 => array_slice($deck, 0, $size),
                1 => array_slice($deck, $size, $size),
                2 => array_slice($deck, $size * 2, $size),
            ],
        ];
    }
}
