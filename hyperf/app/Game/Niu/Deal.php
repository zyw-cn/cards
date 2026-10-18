<?php

declare(strict_types=1);

namespace App\Game\Niu;

/**
 * 斗牛发牌（纯函数，无 IO）
 *
 * - 去掉大小王，共 52 张
 * - 编码：A/B/C/D + 1~13 = 黑桃/红桃/梅花/方片 + A~K
 * - 座位号可能不连续（有人中途离开后座位空洞），必须用 dealToSeats
 */
final class Deal
{
    /**
     * 构建并洗牌一副牌
     *
     * @return list<string>
     */
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
     * 按实际座位列表发牌：每人 CARDS_PER_PLAYER 张
     *
     * @param list<int> $seats 当前在座座位号（可非连续，如 0,1,3）
     * @return array{hands: array<int, list<string>>, remain: list<string>}
     *         hands 的 key 为座位号；remain 为发完后剩余牌
     */
    public static function dealToSeats(array $seats): array
    {
        $seats = array_values(array_unique(array_map('intval', $seats)));
        sort($seats);
        $n = count($seats);
        if ($n < NiuConsts::MIN_PLAYERS || $n > NiuConsts::MAX_PLAYERS) {
            throw new \InvalidArgumentException('发牌人数不合法');
        }
        $deck = self::buildDeck();
        $per = NiuConsts::CARDS_PER_PLAYER;
        $hands = [];
        foreach ($seats as $i => $seat) {
            $hands[$seat] = array_slice($deck, $i * $per, $per);
        }
        $remain = array_slice($deck, $n * $per);

        return ['hands' => $hands, 'remain' => $remain];
    }
}
