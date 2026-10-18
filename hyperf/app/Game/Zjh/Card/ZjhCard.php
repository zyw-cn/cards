<?php

declare(strict_types=1);

namespace App\Game\Zjh\Card;

/**
 * 炸金花牌型：评估 / 比大小（纯规则，无 IO）
 *
 * 牌编码：A/B/C/D + 1~13（A=1 … K=13）
 * 牌型从大到小：炸弹(豹子) > 同花顺 > 普通顺子 > 金花(同花) > 对子 > 单张
 * 同类型比点数（A 最大）；顺子：A23 最小，QKA 最大
 * 比牌平局：发起比牌方负（开牌的输）
 */
final class ZjhCard
{
    public const TYPE_HIGH = 1;
    public const TYPE_PAIR = 2;
    public const TYPE_FLUSH = 3;
    public const TYPE_STRAIGHT = 4;
    public const TYPE_STRAIGHT_FLUSH = 5;
    public const TYPE_LEOPARD = 6;

    private static array $suitWeight = ['A' => 4, 'B' => 3, 'C' => 2, 'D' => 1];

    public static function facePoint(string $card): int
    {
        $card = strtoupper(trim($card));
        if (preg_match('/^[A-D](\d{1,2})$/', $card, $m)) {
            return max(1, min(13, (int) $m[1]));
        }

        return 0;
    }

    /** 比大小用点数：A=14 最大 */
    public static function rankPoint(int $face): int
    {
        $face = (int) $face;

        return $face === 1 ? 14 : $face;
    }

    public static function suit(string $card): string
    {
        $card = strtoupper(trim($card));

        return $card[0] ?? 'D';
    }

    /**
     * @param list<string> $cards
     * @return array{
     *     type:int, label:string, points:list<int>,
     *     pairPoint:int, straightRank:int, maxCard:string
     * }
     */
    public static function evaluate(array $cards): array
    {
        $cards = array_values(array_map(
            static fn ($c) => strtoupper(trim((string) $c)),
            $cards
        ));
        if (count($cards) !== 3) {
            return [
                'type'         => self::TYPE_HIGH,
                'label'        => '单张',
                'points'       => [],
                'pairPoint'    => 0,
                'straightRank' => 0,
                'maxCard'      => $cards[0] ?? '',
            ];
        }

        $points = array_map([self::class, 'facePoint'], $cards);
        rsort($points);
        $suits = array_map([self::class, 'suit'], $cards);
        $isFlush = count(array_unique($suits)) === 1;
        $straightRank = self::straightRank($points);
        $isStraight = $straightRank > 0;
        $counts = array_count_values($points);
        arsort($counts);
        $pairPoint = 0;
        foreach ($counts as $p => $c) {
            if ($c === 3) {
                return [
                    'type'         => self::TYPE_LEOPARD,
                    'label'        => '炸弹',
                    'points'       => $points,
                    'pairPoint'    => (int) $p,
                    'straightRank' => 0,
                    'maxCard'      => self::pickMaxCard($cards),
                ];
            }
            if ($c === 2) {
                $pairPoint = (int) $p;
            }
        }

        if ($isStraight && $isFlush) {
            return [
                'type'         => self::TYPE_STRAIGHT_FLUSH,
                'label'        => '同花顺',
                'points'       => $points,
                'pairPoint'    => 0,
                'straightRank' => $straightRank,
                'maxCard'      => self::pickMaxCard($cards),
            ];
        }
        // 普通顺子 > 金花
        if ($isStraight) {
            return [
                'type'         => self::TYPE_STRAIGHT,
                'label'        => '顺子',
                'points'       => $points,
                'pairPoint'    => 0,
                'straightRank' => $straightRank,
                'maxCard'      => self::pickMaxCard($cards),
            ];
        }
        if ($isFlush) {
            return [
                'type'         => self::TYPE_FLUSH,
                'label'        => '金花',
                'points'       => $points,
                'pairPoint'    => 0,
                'straightRank' => 0,
                'maxCard'      => self::pickMaxCard($cards),
            ];
        }
        if ($pairPoint > 0) {
            return [
                'type'         => self::TYPE_PAIR,
                'label'        => '对子',
                'points'       => $points,
                'pairPoint'    => $pairPoint,
                'straightRank' => 0,
                'maxCard'      => self::pickMaxCard($cards),
            ];
        }

        return [
            'type'         => self::TYPE_HIGH,
            'label'        => '单张',
            'points'       => $points,
            'pairPoint'    => 0,
            'straightRank' => 0,
            'maxCard'      => self::pickMaxCard($cards),
        ];
    }

    /**
     * 比较两手：正数 a 赢，负数 b 赢，0 平
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    public static function compare(array $a, array $b): int
    {
        $ta = (int) ($a['type'] ?? 0);
        $tb = (int) ($b['type'] ?? 0);
        if ($ta !== $tb) {
            return $ta <=> $tb;
        }

        return match ($ta) {
            self::TYPE_LEOPARD => self::rankPoint((int) $a['pairPoint']) <=> self::rankPoint((int) $b['pairPoint']),
            self::TYPE_STRAIGHT_FLUSH, self::TYPE_STRAIGHT => ((int) $a['straightRank']) <=> ((int) $b['straightRank'])
                ?: self::compareMaxCard($a, $b),
            self::TYPE_PAIR => self::rankPoint((int) $a['pairPoint']) <=> self::rankPoint((int) $b['pairPoint'])
                ?: self::comparePointLists($a['points'] ?? [], $b['points'] ?? [])
                ?: self::compareMaxCard($a, $b),
            default => self::comparePointLists($a['points'] ?? [], $b['points'] ?? [])
                ?: self::compareMaxCard($a, $b),
        };
    }

    /** @param list<int> $points desc */
    private static function straightRank(array $points): int
    {
        $uniq = array_values(array_unique($points));
        if (count($uniq) !== 3) {
            return 0;
        }
        sort($uniq);
        // A23
        if ($uniq === [1, 2, 3]) {
            return 3;
        }
        // QKA 视为 12,13,14
        if ($uniq === [1, 12, 13]) {
            return 14;
        }
        if ($uniq[0] + 1 === $uniq[1] && $uniq[1] + 1 === $uniq[2]) {
            return $uniq[2];
        }

        return 0;
    }

    /** @param list<string> $cards */
    private static function pickMaxCard(array $cards): string
    {
        usort($cards, static function (string $x, string $y): int {
            $px = self::rankPoint(self::facePoint($x));
            $py = self::rankPoint(self::facePoint($y));
            if ($px !== $py) {
                return $py <=> $px;
            }

            return (self::$suitWeight[self::suit($y)] ?? 0) <=> (self::$suitWeight[self::suit($x)] ?? 0);
        });

        return $cards[0] ?? '';
    }

    /** @param list<int> $a @param list<int> $b */
    private static function comparePointLists(array $a, array $b): int
    {
        $a = array_map([self::class, 'rankPoint'], array_values($a));
        $b = array_map([self::class, 'rankPoint'], array_values($b));
        rsort($a);
        rsort($b);
        $n = max(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            $cmp = ((int) ($a[$i] ?? 0)) <=> ((int) ($b[$i] ?? 0));
            if ($cmp !== 0) {
                return $cmp;
            }
        }

        return 0;
    }

    /** @param array<string, mixed> $a @param array<string, mixed> $b */
    private static function compareMaxCard(array $a, array $b): int
    {
        $ca = (string) ($a['maxCard'] ?? '');
        $cb = (string) ($b['maxCard'] ?? '');
        $pa = self::rankPoint(self::facePoint($ca));
        $pb = self::rankPoint(self::facePoint($cb));
        if ($pa !== $pb) {
            return $pa <=> $pb;
        }

        return (self::$suitWeight[self::suit($ca)] ?? 0) <=> (self::$suitWeight[self::suit($cb)] ?? 0);
    }
}
