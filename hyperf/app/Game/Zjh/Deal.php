<?php

declare(strict_types=1);

namespace App\Game\Zjh;

/**
 * 炸金花发牌（纯函数，无 IO）
 *
 * 编码与斗牛一致：A/B/C/D + 1~13，去掉大小王共 52 张；每人 3 张。
 */
final class Deal
{
    /** @return list<string> */
    public static function buildDeck(): array
    {
        $deck = [];
        foreach (['A', 'B', 'C', 'D'] as $suit) {
            for ($point = 1; $point <= 13; $point++) {
                $deck[] = $suit . $point;
            }
        }
        shuffle($deck);

        return $deck;
    }

    /**
     * @param list<int> $seats
     * @return array{hands: array<int, list<string>>, remain: list<string>}
     */
    public static function dealToSeats(array $seats): array
    {
        $seats = array_values(array_unique(array_map('intval', $seats)));
        sort($seats);
        $n = count($seats);
        if ($n < ZjhConsts::MIN_PLAYERS || $n > ZjhConsts::MAX_PLAYERS) {
            throw new \InvalidArgumentException('发牌人数不合法');
        }
        $deck = self::buildDeck();
        $per = ZjhConsts::CARDS_PER_PLAYER;
        $hands = [];
        foreach ($seats as $i => $seat) {
            $hands[$seat] = array_slice($deck, $i * $per, $per);
        }

        return [
            'hands'  => $hands,
            'remain' => array_slice($deck, $n * $per),
        ];
    }
}
