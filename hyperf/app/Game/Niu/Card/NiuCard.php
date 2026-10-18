<?php

declare(strict_types=1);

namespace App\Game\Niu\Card;

/**
 * 斗牛牌型：算牛 / 比大小 / 牌型倍数（纯规则，无 IO）
 *
 * 牌编码：A/B/C/D + 1~13（A=1 … J=11 Q=12 K=13）
 * 算牛计点：A~10 按面值，J/Q/K 一律计 10
 *
 * 算法：在 5 张中找任意 3 张点数之和能被 10 整除；
 * 其余 2 张点数和 mod 10 即为牛几（0 → 牛牛）。找不到则为无牛。
 */
final class NiuCard
{
    /** 无牛 */
    public const TYPE_NONE = 0;

    /** 牛一 ~ 牛九：type 取值 1~9 */

    /** 牛牛（其余两张点数和为 10 的倍数） */
    public const TYPE_NIU_NIU = 10;

    public const TYPE_FIVE_FLOWER = 11;

    public const TYPE_BOMB = 12;

    public const TYPE_FIVE_SMALL = 13;

    /** 同点比花色：黑桃 > 红桃 > 梅花 > 方片 */
    private static array $suitWeight = ['A' => 4, 'B' => 3, 'C' => 2, 'D' => 1];

    /**
     * 单张牌面点数（比大小用，保留 1~13，不把 JQK 压成 10）
     */
    public static function facePoint(string $card): int
    {
        $card = strtoupper(trim($card));
        if (preg_match('/^[A-D](\d{1,2})$/', $card, $m)) {
            return max(1, min(13, (int) $m[1]));
        }

        return 0;
    }

    /** 算牛用的计点：JQK=10 */
    public static function niuPoint(string $card): int
    {
        $p = self::facePoint($card);

        return $p >= 10 ? 10 : $p;
    }

    public static function suit(string $card): string
    {
        $card = strtoupper(trim($card));

        return $card[0] ?? 'D';
    }

    /**
     * 评估一手 5 张牌
     *
     * @param list<string> $cards
     * @return array{type:int, multiple:int, max_card:string, label:string}
     */
    public static function evaluate(array $cards, array $multipliers = []): array
    {
        $cards = array_values(array_map(
            static fn ($c) => strtoupper(trim((string) $c)),
            $cards
        ));
        if (count($cards) !== 5) {
            return [
                'type'     => self::TYPE_NONE,
                'multiple' => 1,
                'max_card' => $cards[0] ?? '',
                'label'    => '无牛',
                'group3'   => [],
                'rest2'    => [],
            ];
        }

        // 特殊牌型优先于普通牛型
        $type = self::specialType($cards);
        if ($type !== self::TYPE_NONE) {
            return [
                'type'     => $type,
                'multiple' => self::typeMultiple($type, $multipliers),
                'max_card' => self::maxCard($cards),
                'label'    => self::typeLabel($type),
                'group3'   => [],
                'rest2'    => [],
            ];
        }

        $best = self::bestNiuSplit($cards);
        $type = (int) ($best['type'] ?? self::TYPE_NONE);

        return [
            'type'     => $type,
            'multiple' => self::typeMultiple($type, $multipliers),
            'max_card' => self::maxCard($cards),
            'label'    => self::typeLabel($type),
            'group3'   => $best['group3'] ?? [],
            'rest2'    => $best['rest2'] ?? [],
        ];
    }

    /**
     * 纯程序算牛：穷举 3 张凑 10 的倍数，取最大牛型，并返回组合拆分。
     *
     * @param list<string> $cards 5 张
     * @return array{type:int, group3:list<string>, rest2:list<string>}
     */
    public static function bestNiuSplit(array $cards): array
    {
        $cards = array_values(array_map(
            static fn ($c) => strtoupper(trim((string) $c)),
            $cards
        ));
        $empty = ['type' => self::TYPE_NONE, 'group3' => [], 'rest2' => []];
        if (count($cards) !== 5) {
            return $empty;
        }

        $bestType = self::TYPE_NONE;
        $bestGroup3 = [];
        $bestRest2 = [];
        $n = count($cards);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                for ($k = $j + 1; $k < $n; $k++) {
                    $sum3 = self::niuPoint($cards[$i]) + self::niuPoint($cards[$j]) + self::niuPoint($cards[$k]);
                    if ($sum3 % 10 !== 0) {
                        continue;
                    }
                    $rest = 0;
                    $restCards = [];
                    for ($t = 0; $t < $n; $t++) {
                        if ($t === $i || $t === $j || $t === $k) {
                            continue;
                        }
                        $rest += self::niuPoint($cards[$t]);
                        $restCards[] = $cards[$t];
                    }
                    $niu = $rest % 10;
                    $cur = $niu === 0 ? self::TYPE_NIU_NIU : $niu;
                    if ($cur > $bestType) {
                        $bestType = $cur;
                        $bestGroup3 = [$cards[$i], $cards[$j], $cards[$k]];
                        $bestRest2 = $restCards;
                    }
                }
            }
        }

        return [
            'type'   => $bestType,
            'group3' => $bestGroup3,
            'rest2'  => $bestRest2,
        ];
    }

    /**
     * 牌型结算倍数（与闲家下注倍数、抢庄倍数相乘）
     * 倍数默认与 createRoomRules.win 一致，也可由房间 rule 覆盖。
     */
    public static function typeMultiple(int $type, array $multipliers = []): int
    {
        $rules = array_merge([
            'niu_niu' => 4,
            'niu_9' => 3,
            'niu_8' => 2,
            'five_flower' => 5,
            'bomb' => 6,
            'five_small' => 8,
        ], $multipliers);
        if ($type === self::TYPE_FIVE_SMALL) {
            return max(1, (int) $rules['five_small']);
        }
        if ($type === self::TYPE_BOMB) {
            return max(1, (int) $rules['bomb']);
        }
        if ($type === self::TYPE_FIVE_FLOWER) {
            return max(1, (int) $rules['five_flower']);
        }
        if ($type === self::TYPE_NIU_NIU) {
            return max(1, (int) $rules['niu_niu']);
        }
        if ($type === 9) {
            return max(1, (int) $rules['niu_9']);
        }
        if ($type === 8) {
            return max(1, (int) $rules['niu_8']);
        }

        return 1;
    }

    public static function typeLabel(int $type): string
    {
        if ($type === self::TYPE_FIVE_SMALL) {
            return '五小牛';
        }
        if ($type === self::TYPE_BOMB) {
            return '炸弹牛';
        }
        if ($type === self::TYPE_FIVE_FLOWER) {
            return '五花牛';
        }
        if ($type <= 0) {
            return '无牛';
        }
        if ($type >= self::TYPE_NIU_NIU) {
            return '牛牛';
        }
        $map = [1 => '一', 2 => '二', 3 => '三', 4 => '四', 5 => '五', 6 => '六', 7 => '七', 8 => '八', 9 => '九'];

        return '牛' . ($map[$type] ?? (string) $type);
    }

    /** @param list<string> $cards */
    private static function specialType(array $cards): int
    {
        $points = array_map(static fn (string $card): int => self::facePoint($card), $cards);
        $niuPoints = array_map(static fn (string $card): int => self::niuPoint($card), $cards);

        if (max($niuPoints) <= 5 && array_sum($niuPoints) <= 10) {
            return self::TYPE_FIVE_SMALL;
        }

        $counts = array_count_values($points);
        if ($counts !== [] && max($counts) >= 4) {
            return self::TYPE_BOMB;
        }

        if (min($points) >= 11) {
            return self::TYPE_FIVE_FLOWER;
        }

        return self::TYPE_NONE;
    }

    /**
     * 五张中最大的一张（先比点数，再比花色）——同牛型时用来决胜负
     *
     * @param list<string> $cards
     */
    public static function maxCard(array $cards): string
    {
        $best = '';
        $bestFace = -1;
        $bestSuit = -1;
        foreach ($cards as $c) {
            $face = self::facePoint($c);
            $sw = self::$suitWeight[self::suit($c)] ?? 0;
            if ($face > $bestFace || ($face === $bestFace && $sw > $bestSuit)) {
                $best = $c;
                $bestFace = $face;
                $bestSuit = $sw;
            }
        }

        return $best;
    }

    /**
     * 比较两手牌：正数 a 赢，负数 b 赢，0 平局（不结算）
     * 顺序：牛型 → 最大牌点数 → 最大牌花色
     *
     * @param array{type:int, max_card:string} $a
     * @param array{type:int, max_card:string} $b
     */
    public static function compare(array $a, array $b): int
    {
        $ta = (int) ($a['type'] ?? 0);
        $tb = (int) ($b['type'] ?? 0);
        if ($ta !== $tb) {
            return $ta <=> $tb;
        }
        $ca = (string) ($a['max_card'] ?? '');
        $cb = (string) ($b['max_card'] ?? '');
        $fa = self::facePoint($ca);
        $fb = self::facePoint($cb);
        if ($fa !== $fb) {
            return $fa <=> $fb;
        }
        $sa = self::$suitWeight[self::suit($ca)] ?? 0;
        $sb = self::$suitWeight[self::suit($cb)] ?? 0;

        return $sa <=> $sb;
    }
}
