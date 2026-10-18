<?php

declare(strict_types=1);

namespace App\Game\Dou\Card;

use App\Game\Card\CardCode;

/**
 * 斗地主扑克牌型识别与比较（归属 Dou Module）
 *
 * 牌面编码：
 *   A/B/C/D + 3~15 = 黑桃/红桃/梅花/方片（3~K/A/2）
 *   E16=小王，E17=大王
 * 比大小只看点数，忽略花色。
 */
class LandlordCard
{
    /** 牌型常量 */
    public const CARD_TYPE_SINGLE = '单张';
    public const CARD_TYPE_PAIR = '对子';
    public const CARD_TYPE_TRIPLE = '三张';
    public const CARD_TYPE_TRIPLE_ONE = '三带一';
    public const CARD_TYPE_TRIPLE_TWO = '三带二';
    public const CARD_TYPE_FOUR_TWO = '四带二';
    public const CARD_TYPE_FOUR_TWO_PAIR = '四带两对';
    public const CARD_TYPE_STRAIGHT = '顺子';
    public const CARD_TYPE_SEQUENCE_PAIR = '连对';
    public const CARD_TYPE_PLANE = '飞机';
    public const CARD_TYPE_BOMB = '炸弹';
    public const CARD_TYPE_ROCKET = '王炸';
    public const CARD_TYPE_INVALID = '无效牌型';

    /** 牌面点数权重（用于比大小与排序），3最小、2=15、小王16、大王17 */
    private static array $weightMap = [
        3 => 3, 4 => 4, 5 => 5, 6 => 6, 7 => 7, 8 => 8, 9 => 9, 10 => 10,
        11 => 11, 12 => 12, 13 => 13, 14 => 14, 15 => 15, 16 => 16, 17 => 17,
    ];

    /** 牌面数字转可读字符串（用于日志/调试） */
    private static array $labelMap = [
        3 => '3', 4 => '4', 5 => '5', 6 => '6', 7 => '7', 8 => '8', 9 => '9',
        10 => '10', 11 => 'J', 12 => 'Q', 13 => 'K', 14 => 'A', 15 => '2',
        16 => '小王', 17 => '大王',
    ];

    /**
     * 将单张牌编码转为点数（忽略花色）
     * 支持 "A14" / "E17" / 14
     */
    public static function normalizeCode(string|int $card): string
    {
        return CardCode::normalize($card);
    }

    public static function toPoint(string|int $card): int
    {
        if (is_int($card)) {
            return $card;
        }
        $card = CardCode::normalize($card);
        if ($card === '') {
            return 0;
        }
        // 纯数字
        if (ctype_digit($card)) {
            return (int) $card;
        }
        // A14 / E16
        return (int) substr($card, 1);
    }

    /**
     * 牌数组统一转为点数数组（比牌/验型前调用）
     * @param array<int, string|int> $cards
     * @return list<int>
     */
    public static function toPoints(array $cards): array
    {
        $points = [];
        foreach ($cards as $c) {
            $points[] = self::toPoint($c);
        }
        return $points;
    }

    /**
     * 按权重升序排序手牌（原地修改），3 最小、2=15、小王 16、大王 17
     * @param array<int, int|string> $cards 牌面（点数或编码）
     */
    public static function sortCards(array &$cards): void
    {
        usort($cards, static function ($a, $b): int {
            $pa = self::toPoint($a);
            $pb = self::toPoint($b);
            return (self::$weightMap[$pa] ?? $pa) <=> (self::$weightMap[$pb] ?? $pb);
        });
    }

    /**
     * 统计每张牌的点数及出现次数（不修改入参）
     * @param array<int, int|string> $cards
     * @return array<int, int> 点数 => 出现次数
     */
    public static function countCardNumbers(array $cards): array
    {
        $points = self::toPoints($cards);
        self::sortCards($points);
        $count = [];
        foreach ($points as $card) {
            $count[$card] = ($count[$card] ?? 0) + 1;
        }
        return $count;
    }

    /**
     * 分析牌型
     * @param array<int, int|string> $cards 牌面（点数或 A14/E17 编码）
     * @return array{0: string, 1: int, 2: int} [牌型名称, 主牌权重, 牌张数]
     */
    public static function analyzeCards(array $cards): array
    {
        $points = self::toPoints($cards);
        if ($points === []) {
            return [self::CARD_TYPE_INVALID, 0, 0];
        }

        $countMap = self::countCardNumbers($points);
        $values = array_keys($countMap);
        $counts = array_values($countMap);
        $len = count($points);

        // 王炸：仅 16+17 两张（不依赖出牌顺序）
        if ($len === 2 && isset($countMap[16], $countMap[17])) {
            return [self::CARD_TYPE_ROCKET, 17, 2];
        }

        // 炸弹：四张相同
        if ($len === 4 && count($countMap) === 1 && $counts[0] === 4) {
            return [self::CARD_TYPE_BOMB, $values[0], 4];
        }

        // 单张
        if ($len === 1) {
            return [self::CARD_TYPE_SINGLE, $values[0], 1];
        }

        // 对子
        if ($len === 2 && count($countMap) === 1 && $counts[0] === 2) {
            return [self::CARD_TYPE_PAIR, $values[0], 2];
        }

        // 三张
        if ($len === 3 && count($countMap) === 1 && $counts[0] === 3) {
            return [self::CARD_TYPE_TRIPLE, $values[0], 3];
        }

        // 三带一
        if ($len === 4 && count($countMap) === 2 && in_array(3, $counts, true)) {
            $base = array_search(3, $countMap, true);
            return [self::CARD_TYPE_TRIPLE_ONE, (int) $base, 4];
        }

        // 三带二：三张 + 一对
        if ($len === 5 && count($countMap) === 2 && in_array(3, $counts, true) && in_array(2, $counts, true)) {
            $base = array_search(3, $countMap, true);
            return [self::CARD_TYPE_TRIPLE_TWO, (int) $base, 5];
        }

        // 四带二：四张 + 两张（两单或一对）
        if ($len === 6 && in_array(4, $counts, true)) {
            $base = array_search(4, $countMap, true);
            $rest = $len - 4;
            if ($rest === 2 && $base !== false) {
                return [self::CARD_TYPE_FOUR_TWO, (int) $base, 6];
            }
        }

        // 四带两对：四张 + 两个对子
        if ($len === 8 && in_array(4, $counts, true)) {
            $base = array_search(4, $countMap, true);
            $pairCount = 0;
            foreach ($countMap as $pt => $cnt) {
                if ((int) $cnt === 2) {
                    $pairCount++;
                } elseif ((int) $cnt !== 4) {
                    $pairCount = -1;
                    break;
                }
            }
            if ($base !== false && $pairCount === 2) {
                return [self::CARD_TYPE_FOUR_TWO_PAIR, (int) $base, 8];
            }
        }

        // 顺子
        if ($len >= 5 && count($countMap) === $len) {
            $min = min($values);
            $max = max($values);
            if ($max - $min + 1 === $len && $max <= 15) {
                return [self::CARD_TYPE_STRAIGHT, $max, $len];
            }
        }

        // 连对
        if ($len >= 6 && $len % 2 === 0) {
            $allPair = true;
            foreach ($counts as $c) {
                if ($c !== 2) {
                    $allPair = false;
                    break;
                }
            }
            if ($allPair) {
                $min = min($values);
                $max = max($values);
                $pairCount = $len / 2;
                if ($max - $min + 1 === $pairCount && $max <= 15) {
                    return [self::CARD_TYPE_SEQUENCE_PAIR, $max, $len];
                }
            }
        }

        // 飞机
        if (count($countMap) >= 2 && max($counts) === 3) {
            $tripleValues = [];
            foreach ($countMap as $point => $cnt) {
                if ($cnt === 3) {
                    $tripleValues[] = $point;
                }
            }
            sort($tripleValues);
            $tripleLen = count($tripleValues);
            if ($tripleLen >= 2 && max($tripleValues) - min($tripleValues) + 1 === $tripleLen) {
                return [self::CARD_TYPE_PLANE, max($tripleValues), $len];
            }
        }

        return [self::CARD_TYPE_INVALID, 0, $len];
    }

    /**
     * 判断当前出的牌是否大于上一手
     * @param array<int, int|string> $current
     * @param array<int, int|string> $last
     */
    public static function isBigger(array $current, array $last): bool
    {
        if ($last === []) {
            return true;
        }

        [$curType, $curWeight, $curLen] = self::analyzeCards($current);
        [$lastType, $lastWeight, $lastLen] = self::analyzeCards($last);

        if ($curType === self::CARD_TYPE_INVALID) {
            return false;
        }
        if ($lastType === self::CARD_TYPE_INVALID) {
            return true;
        }

        if ($curType === self::CARD_TYPE_ROCKET) {
            return true;
        }
        if ($lastType === self::CARD_TYPE_ROCKET) {
            return false;
        }

        if ($curType === self::CARD_TYPE_BOMB && $lastType !== self::CARD_TYPE_BOMB) {
            return true;
        }
        if ($lastType === self::CARD_TYPE_BOMB && $curType !== self::CARD_TYPE_BOMB) {
            return false;
        }

        if ($curType === $lastType && $curLen === $lastLen) {
            return $curWeight > $lastWeight;
        }

        return false;
    }

    /**
     * 若整手剩牌可一次合法出完，返回整手；否则返回空。
     *
     * @param list<string> $hand
     * @param list<string> $lastCards
     * @return list<string>
     */
    public static function suggestFinishPlay(array $hand, array $lastCards = []): array
    {
        $hand = array_values(array_filter(array_map(
            static fn ($c) => strtoupper(trim((string) $c)),
            $hand
        ), static fn (string $c): bool => $c !== ''));
        if ($hand === []) {
            return [];
        }

        [$type] = self::analyzeCards($hand);
        if ($type === self::CARD_TYPE_INVALID) {
            return [];
        }

        return $lastCards === [] || self::isBigger($hand, $lastCards) ? $hand : [];
    }

    /**
     * 托管/超时代打。
     * 首出先出顺子、连对、飞机、三带、对子，再出单张；不拆炸弹和王炸。
     * 跟牌用最小同型，不拆炸弹。炸弹只在上手已是炸，或对手剩很少牌时才用。
     * 农民不压队友，除非对手快出完。
     *
     * @param list<string> $hand
     * @param list<string> $lastCards
     * @param array{threat?:bool,partnerLead?:bool} $ctx
     * @return list<string>
     */
    public static function suggestPlay(array $hand, array $lastCards = [], array $ctx = []): array
    {
        $hand = array_values(array_filter(array_map(
            static fn ($c) => strtoupper(trim((string) $c)),
            $hand
        ), static fn (string $c): bool => $c !== ''));
        if ($hand === []) {
            return [];
        }

        $finish = self::suggestFinishPlay($hand, $lastCards);
        if ($finish !== []) {
            return $finish;
        }

        if ($lastCards === []) {
            return self::suggestLead($hand);
        }

        $threat = ! empty($ctx['threat']);
        if (! empty($ctx['partnerLead']) && ! $threat) {
            return [];
        }

        [$lastType, $lastWeight, $lastLen] = self::analyzeCards($lastCards);
        if ($lastType === self::CARD_TYPE_INVALID || $lastType === self::CARD_TYPE_ROCKET) {
            return [];
        }

        $same = self::findSameTypeBigger(self::withoutBombs($hand), $lastType, $lastWeight, $lastLen);
        if ($same !== []) {
            return $same;
        }

        if ($lastType !== self::CARD_TYPE_BOMB && ! $threat) {
            return [];
        }

        $minBomb = $lastType === self::CARD_TYPE_BOMB ? $lastWeight : 0;
        $bomb = self::findBombCards($hand, $minBomb);
        if ($bomb !== []) {
            return $bomb;
        }

        return self::findRocketCards($hand);
    }

    /**
     * 首出：保留炸弹和王炸，优先打出一组小牌组合。
     *
     * @param list<string> $hand
     * @return list<string>
     */
    private static function suggestLead(array $hand): array
    {
        $soft = self::withoutBombs($hand);
        if ($soft === []) {
            $bomb = self::findBombCards($hand, 0);

            return $bomb !== [] ? $bomb : (self::findRocketCards($hand) ?: [$hand[0]]);
        }

        $map = self::groupHand($soft);
        $straight = self::bestRun($map, 1, 5, true) ?: self::bestRun($map, 1, 5, false);
        if ($straight !== []) {
            return $straight;
        }
        $seq = self::bestRun($map, 2, 3, true) ?: self::bestRun($map, 2, 3, false);
        if ($seq !== []) {
            return $seq;
        }
        $plane = self::leadPlane($map);
        if ($plane !== []) {
            return $plane;
        }
        $triple = self::leadTriple($map);
        if ($triple !== []) {
            return $triple;
        }
        $pair = self::leadPair($map);
        if ($pair !== []) {
            return $pair;
        }

        return [self::leadSingle($map, $soft)];
    }

    /**
     * 去掉四张炸弹，以及成对的王（王炸不拆）。
     *
     * @param list<string> $hand
     * @return list<string>
     */
    private static function withoutBombs(array $hand): array
    {
        $map = self::groupHand($hand);
        $drop = [];
        foreach ($map as $point => $codes) {
            if (count($codes) >= 4) {
                $drop[(int) $point] = true;
            }
        }
        if (isset($map[16], $map[17])) {
            $drop[16] = true;
            $drop[17] = true;
        }
        $out = [];
        foreach ($hand as $code) {
            if (! isset($drop[self::toPoint($code)])) {
                $out[] = $code;
            }
        }

        return $out;
    }

    /**
     * 最小起点的最长顺子/连对。exact=true 时单张顺不拆对、连对不拆三张。
     *
     * @param array<int, list<string>> $map
     * @return list<string>
     */
    private static function bestRun(array $map, int $needPer, int $minLen, bool $exact): array
    {
        $best = [];
        $bestLen = 0;
        $bestMax = 99;
        for ($start = 3; $start <= 14; $start++) {
            $picked = [];
            $len = 0;
            for ($p = $start; $p <= 14; $p++) {
                $have = isset($map[$p]) ? count($map[$p]) : 0;
                if ($have < $needPer || ($exact && $have !== $needPer)) {
                    break;
                }
                array_push($picked, ...array_slice($map[$p], 0, $needPer));
                $len++;
                if ($len < $minLen) {
                    continue;
                }
                if ($len > $bestLen || ($len === $bestLen && $p < $bestMax)) {
                    $bestLen = $len;
                    $bestMax = $p;
                    $best = $picked;
                }
            }
        }

        return $best;
    }

    /**
     * @param array<int, list<string>> $map
     * @return list<string>
     */
    private static function leadPlane(array $map): array
    {
        for ($start = 3; $start <= 13; $start++) {
            if (! isset($map[$start], $map[$start + 1]) || count($map[$start]) < 3 || count($map[$start + 1]) < 3) {
                continue;
            }
            $main = [...array_slice($map[$start], 0, 3), ...array_slice($map[$start + 1], 0, 3)];
            $kickers = [];
            foreach ($map as $p => $codes) {
                if ($p === $start || $p === $start + 1) {
                    continue;
                }
                foreach ($codes as $code) {
                    $kickers[] = $code;
                    if (count($kickers) === 2) {
                        $play = [...$main, ...$kickers];
                        if (self::analyzeCards($play)[0] !== self::CARD_TYPE_INVALID) {
                            return $play;
                        }
                    }
                }
            }
        }

        return [];
    }

    /**
     * @param array<int, list<string>> $map
     * @return list<string>
     */
    private static function leadTriple(array $map): array
    {
        foreach ($map as $point => $codes) {
            if ((int) $point > 14 || count($codes) < 3) {
                continue;
            }
            $triple = array_slice($codes, 0, 3);
            $kicker = null;
            foreach ($map as $p2 => $codes2) {
                if ($p2 === $point || $codes2 === []) {
                    continue;
                }
                $kicker = $codes2[0];
                if (count($codes2) === 1) {
                    break;
                }
            }
            if ($kicker === null) {
                continue;
            }
            $play = [...$triple, $kicker];
            if (self::analyzeCards($play)[0] !== self::CARD_TYPE_INVALID) {
                return $play;
            }
        }

        return [];
    }

    /**
     * @param array<int, list<string>> $map
     * @return list<string>
     */
    private static function leadPair(array $map): array
    {
        $fallback = [];
        foreach ($map as $codes) {
            if (count($codes) < 2) {
                continue;
            }
            $pair = array_slice($codes, 0, 2);
            if (count($codes) === 2) {
                return $pair;
            }
            if ($fallback === []) {
                $fallback = $pair;
            }
        }

        return $fallback;
    }

    /**
     * @param array<int, list<string>> $map
     * @param list<string> $soft
     */
    private static function leadSingle(array $map, array $soft): string
    {
        foreach ($map as $point => $codes) {
            if ((int) $point < 15 && count($codes) === 1) {
                return $codes[0];
            }
        }
        $sorted = $soft;
        self::sortCards($sorted);

        return $sorted[0];
    }

    /**
     * @param list<string> $hand
     * @return array<int, list<string>> 点数 => 牌编码列表
     */
    private static function groupHand(array $hand): array
    {
        $map = [];
        foreach ($hand as $code) {
            $p = self::toPoint($code);
            if ($p <= 0) {
                continue;
            }
            $map[$p][] = $code;
        }
        ksort($map);
        return $map;
    }

    /**
     * @param list<string> $hand
     * @return list<string>
     */
    private static function findSameTypeBigger(array $hand, string $lastType, int $lastWeight, int $lastLen): array
    {
        $map = self::groupHand($hand);
        return match ($lastType) {
            self::CARD_TYPE_SINGLE => self::pickSingleBigger($hand, $lastWeight),
            self::CARD_TYPE_PAIR => self::pickNOfAKind($map, 2, $lastWeight),
            self::CARD_TYPE_TRIPLE => self::pickNOfAKind($map, 3, $lastWeight),
            self::CARD_TYPE_TRIPLE_ONE => self::pickTripleWithKicker($map, $hand, $lastWeight, 1),
            self::CARD_TYPE_TRIPLE_TWO => self::pickTripleWithKicker($map, $hand, $lastWeight, 2),
            self::CARD_TYPE_FOUR_TWO => self::pickFourWithKicker($map, $hand, $lastWeight, 2),
            self::CARD_TYPE_FOUR_TWO_PAIR => self::pickFourWithKicker($map, $hand, $lastWeight, 4),
            self::CARD_TYPE_BOMB => self::findBombCards($hand, $lastWeight),
            self::CARD_TYPE_STRAIGHT => self::pickStraight($map, $lastLen, $lastWeight, 1),
            self::CARD_TYPE_SEQUENCE_PAIR => self::pickStraight($map, intdiv($lastLen, 2), $lastWeight, 2),
            self::CARD_TYPE_PLANE => self::pickPlane($map, $hand, $lastLen, $lastWeight),
            default => [],
        };
    }

    /** @param list<string> $hand @return list<string> */
    private static function pickSingleBigger(array $hand, int $lastWeight): array
    {
        $sorted = $hand;
        self::sortCards($sorted);
        foreach ($sorted as $code) {
            if (self::toPoint($code) > $lastWeight) {
                return [$code];
            }
        }
        return [];
    }

    /**
     * @param array<int, list<string>> $map
     * @return list<string>
     */
    private static function pickNOfAKind(array $map, int $n, int $lastWeight): array
    {
        foreach ($map as $point => $codes) {
            if ($point <= $lastWeight) {
                continue;
            }
            if (count($codes) >= $n) {
                return array_slice($codes, 0, $n);
            }
        }
        return [];
    }

    /**
     * @param array<int, list<string>> $map
     * @param list<string> $hand
     * @return list<string>
     */
    private static function pickTripleWithKicker(array $map, array $hand, int $lastWeight, int $kickerNeed): array
    {
        foreach ($map as $point => $codes) {
            if ($point <= $lastWeight || count($codes) < 3) {
                continue;
            }
            $triple = array_slice($codes, 0, 3);
            if ($kickerNeed === 1) {
                $sorted = $hand;
                self::sortCards($sorted);
                foreach ($sorted as $code) {
                    if (self::toPoint($code) !== $point) {
                        return [...$triple, $code];
                    }
                }
            } else {
                foreach ($map as $p2 => $codes2) {
                    if ($p2 === $point || count($codes2) < 2) {
                        continue;
                    }
                    return [...$triple, ...array_slice($codes2, 0, 2)];
                }
            }
        }
        return [];
    }

    /**
     * 四带二(kickerNeed=2) / 四带两对(kickerNeed=4)
     *
     * @param array<int, list<string>> $map
     * @param list<string> $hand
     * @return list<string>
     */
    private static function pickFourWithKicker(array $map, array $hand, int $lastWeight, int $kickerNeed): array
    {
        foreach ($map as $point => $codes) {
            if ($point <= $lastWeight || count($codes) < 4) {
                continue;
            }
            $four = array_slice($codes, 0, 4);
            if ($kickerNeed === 2) {
                foreach ($map as $p2 => $codes2) {
                    if ($p2 === $point || count($codes2) < 2) {
                        continue;
                    }
                    return [...$four, ...array_slice($codes2, 0, 2)];
                }
                $kickers = [];
                $sorted = $hand;
                self::sortCards($sorted);
                foreach ($sorted as $code) {
                    if (self::toPoint($code) === $point) {
                        continue;
                    }
                    $kickers[] = $code;
                    if (count($kickers) === 2) {
                        return [...$four, ...$kickers];
                    }
                }
            } elseif ($kickerNeed === 4) {
                $pairPoints = [];
                foreach ($map as $p2 => $codes2) {
                    if ($p2 !== $point && count($codes2) >= 2) {
                        $pairPoints[] = $p2;
                    }
                }
                if (count($pairPoints) >= 2) {
                    return [
                        ...$four,
                        ...array_slice($map[$pairPoints[0]], 0, 2),
                        ...array_slice($map[$pairPoints[1]], 0, 2),
                    ];
                }
            }
        }
        return [];
    }

    /**
     * 顺子 needPer=1；连对 needPer=2。maxPoint 为比较权重。
     *
     * @param array<int, list<string>> $map
     * @return list<string>
     */
    private static function pickStraight(array $map, int $runLen, int $lastWeight, int $needPer): array
    {
        if ($runLen < 1) {
            return [];
        }
        // 顺子/连对不含 2(15) 与王
        for ($max = $lastWeight + 1; $max <= 14; $max++) {
            $min = $max - $runLen + 1;
            if ($min < 3) {
                continue;
            }
            $ok = true;
            $picked = [];
            for ($p = $min; $p <= $max; $p++) {
                if (! isset($map[$p]) || count($map[$p]) < $needPer) {
                    $ok = false;
                    break;
                }
                array_push($picked, ...array_slice($map[$p], 0, $needPer));
            }
            if ($ok) {
                return $picked;
            }
        }
        return [];
    }

    /**
     * 飞机：按张数粗分纯飞机 / 带单 / 带对（与 analyzeCards 对齐）
     *
     * @param array<int, list<string>> $map
     * @param list<string> $hand
     * @return list<string>
     */
    private static function pickPlane(array $map, array $hand, int $lastLen, int $lastWeight): array
    {
        // 纯飞机：张数 = 3*n；带单 4*n；带对 5*n
        $tripleCount = 0;
        $kickerPer = 0;
        if ($lastLen % 5 === 0) {
            $tripleCount = intdiv($lastLen, 5);
            $kickerPer = 2;
        } elseif ($lastLen % 4 === 0) {
            $tripleCount = intdiv($lastLen, 4);
            $kickerPer = 1;
        } elseif ($lastLen % 3 === 0) {
            $tripleCount = intdiv($lastLen, 3);
            $kickerPer = 0;
        }
        if ($tripleCount < 2) {
            return [];
        }

        for ($max = $lastWeight + 1; $max <= 14; $max++) {
            $min = $max - $tripleCount + 1;
            if ($min < 3) {
                continue;
            }
            $ok = true;
            $main = [];
            $used = [];
            for ($p = $min; $p <= $max; $p++) {
                if (! isset($map[$p]) || count($map[$p]) < 3) {
                    $ok = false;
                    break;
                }
                array_push($main, ...array_slice($map[$p], 0, 3));
                $used[$p] = true;
            }
            if (! $ok) {
                continue;
            }
            if ($kickerPer === 0) {
                return $main;
            }
            $kickers = [];
            if ($kickerPer === 1) {
                $sorted = $hand;
                self::sortCards($sorted);
                $seen = [];
                foreach ($sorted as $code) {
                    $pt = self::toPoint($code);
                    if (isset($used[$pt]) || isset($seen[$pt])) {
                        continue;
                    }
                    $kickers[] = $code;
                    $seen[$pt] = true;
                    if (count($kickers) >= $tripleCount) {
                        return [...$main, ...$kickers];
                    }
                }
            } else {
                foreach ($map as $p2 => $codes2) {
                    if (isset($used[$p2]) || count($codes2) < 2) {
                        continue;
                    }
                    array_push($kickers, ...array_slice($codes2, 0, 2));
                    if (count($kickers) >= $tripleCount * 2) {
                        return [...$main, ...$kickers];
                    }
                }
            }
        }
        return [];
    }

    /** @param list<string> $hand @return list<string> */
    private static function findBombCards(array $hand, int $minWeight): array
    {
        $map = self::groupHand($hand);
        foreach ($map as $point => $codes) {
            if ($point <= $minWeight) {
                continue;
            }
            if (count($codes) >= 4) {
                return array_slice($codes, 0, 4);
            }
        }
        return [];
    }

    /** @param list<string> $hand @return list<string> */
    private static function findRocketCards(array $hand): array
    {
        $map = self::groupHand($hand);
        if (isset($map[16][0], $map[17][0])) {
            return [$map[16][0], $map[17][0]];
        }
        return [];
    }

    /**
     * 格式化为可读字符串（调试用）
     * @param array<int, int|string> $cards
     */
    public static function formatCards(array $cards): string
    {
        $str = [];
        foreach ($cards as $c) {
            $p = self::toPoint($c);
            $str[] = self::$labelMap[$p] ?? (string) $c;
        }
        return implode(' ', $str);
    }
}
