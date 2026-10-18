<?php

declare(strict_types=1);

namespace App\Game\Lz;

/**
 * 癞子斗地主发牌（纯函数，无 IO）
 *
 * 牌副：普通 54 张 + 癞子 F55 → 共 55 张。
 * 发牌：3 人各 17 张，剩 4 张底牌。
 */
final class Deal
{
    /**
     * 构建一副牌（带花色编码）：
     *   A/B/C/D + 3~15 = 黑桃/红桃/梅花/方片
     *   E16=小王，E17=大王
     *   F55=癞子（图集帧 F55，兼容旧码 W55）
     *
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
        $deck[] = 'E16';
        $deck[] = 'E17';
        $deck[] = LzConsts::WILD_CARD;
        shuffle($deck);

        return $deck;
    }

    /**
     * 发牌：3 人各 17 张，剩 4 张底牌
     *
     * @return array{hands: array<int, list<string>>, remain: list<string>}
     */
    public static function deal(): array
    {
        $deck = self::buildDeck();
        $hands = [
            0 => array_slice($deck, 0, 17),
            1 => array_slice($deck, 17, 17),
            2 => array_slice($deck, 34, 17),
        ];
        $remain = array_slice($deck, 51, 4);

        return ['hands' => $hands, 'remain' => $remain];
    }
}
