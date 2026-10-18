<?php

declare(strict_types=1);

namespace App\Game\Hzmj;

/**
 * 红中麻将发牌（纯函数，无 IO）
 *
 * 墙：4×(万条筒)+4×红中 = 112；庄家 14，其余 13。
 */
final class Deal
{
    /**
     * @return list<int>
     */
    public static function buildWall(): array
    {
        $wall = [];
        foreach ([HzmjConsts::CHARACTER, HzmjConsts::BAMBOO, HzmjConsts::DOT] as $suit) {
            foreach ($suit as $t) {
                for ($i = 0; $i < 4; ++$i) {
                    $wall[] = $t;
                }
            }
        }
        for ($i = 0; $i < 4; ++$i) {
            $wall[] = HzmjConsts::KING_TILE;
        }

        return $wall;
    }

    /**
     * 开局骰子：每位座位两枚，点数 1~6（Cocos diceList 形状）
     *
     * @return list<list<int>>
     */
    public static function throwDice(int $playerNum): array
    {
        $playerNum = max(HzmjConsts::MIN_PLAYERS, min(HzmjConsts::MAX_PLAYERS, $playerNum));
        $list = [];
        for ($i = 0; $i < $playerNum; ++$i) {
            $list[] = [random_int(1, 6), random_int(1, 6)];
        }

        return $list;
    }

    /**
     * @return array{hands: list<list<int>>, wall: list<int>}
     */
    public static function deal(int $playerNum, int $dealerSeat): array
    {
        $playerNum = max(HzmjConsts::MIN_PLAYERS, min(HzmjConsts::MAX_PLAYERS, $playerNum));
        $dealerSeat = max(0, min($playerNum - 1, $dealerSeat));

        $wall = self::buildWall();
        shuffle($wall);

        $hands = [];
        $idx = 0;
        for ($p = 0; $p < $playerNum; ++$p) {
            $count = $p === $dealerSeat ? 14 : 13;
            $hands[$p] = array_slice($wall, $idx, $count);
            $idx += $count;
        }
        $remain = array_slice($wall, $idx);

        return [
            'hands' => $hands,
            'wall'  => array_values($remain),
        ];
    }
}
