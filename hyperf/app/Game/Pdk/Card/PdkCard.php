<?php

declare(strict_types=1);

namespace App\Game\Pdk\Card;

use App\Game\Card\CardCode;

/**
 * 跑得快牌型识别与比较（经典三人规则）
 *
 * 编码同斗地主：A/B/C/D + 3~15；无比王炸。
 * 顺子/连对/飞机不含 2(15)。
 * 光山跑得快：三带一随时可出；三带二必须是一对；三不带只能最后一手。
 * 飞机不带只能最后一手；每组带一张单或一对可随时出。没有甩尾只带一张。
 * 四带二单、四带两对不算炸弹。没有四带一、四带三。
 */
class PdkCard
{
    public const CARD_TYPE_SINGLE = '单张';
    public const CARD_TYPE_PAIR = '对子';
    /** 三不带：纯三张，不带牌 */
    public const CARD_TYPE_TRIPLE = '三不带';
    public const CARD_TYPE_TRIPLE_ONE = '三带一';
    public const CARD_TYPE_TRIPLE_TWO = '三带二';
    public const CARD_TYPE_STRAIGHT = '顺子';
    public const CARD_TYPE_SEQUENCE_PAIR = '连对';
    public const CARD_TYPE_PLANE = '飞机';
    public const CARD_TYPE_FOUR_TWO = '四带二单';
    public const CARD_TYPE_FOUR_TWO_PAIR = '四带两对';
    public const CARD_TYPE_PLANE_SINGLE = '飞机带单';
    public const CARD_TYPE_PLANE_PAIR = '飞机带对';
    public const CARD_TYPE_PLANE_TAIL = '飞机甩尾';
    public const CARD_TYPE_BOMB = '炸弹';
    public const CARD_TYPE_INVALID = '无效牌型';

    /** 三个 A 的比较权重：高于 2(15) 与任何炸弹 */
    public const TRIPLE_ACE_WEIGHT = 100;

    private static array $weightMap = [
        3 => 3, 4 => 4, 5 => 5, 6 => 6, 7 => 7, 8 => 8, 9 => 9, 10 => 10,
        11 => 11, 12 => 12, 13 => 13, 14 => 14, 15 => 15,
    ];

    /**
     * 三条比较权重：三个 A 最大（高于 2）
     * 本玩法副牌仅 1 张 2，三条 2 本不可成；仍显式抬高 A，避免规则歧义。
     */
    private static function tripleCompareWeight(int $point): int
    {
        if ($point === 14) {
            return self::TRIPLE_ACE_WEIGHT;
        }

        return self::$weightMap[$point] ?? $point;
    }

    /** 单纯的三个 A（三带二里的 AAA 不算） */
    private static function isTripleAce(string $type, int $weight, int $len): bool
    {
        return $type === self::CARD_TYPE_TRIPLE && $len === 3 && $weight === self::TRIPLE_ACE_WEIGHT;
    }

    /**
     * 是否触发「最大牌」即时结算：四张炸弹，或三个 A（本玩法最大，压得过任何炸）
     *
     * @param array<int, int|string> $cards
     */
    public static function isBombSettlePlay(array $cards): bool
    {
        [$type, $weight, $len] = self::analyzeCards($cards);

        return $type === self::CARD_TYPE_BOMB || self::isTripleAce($type, $weight, $len);
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
        if (ctype_digit($card)) {
            return (int) $card;
        }

        return (int) substr($card, 1);
    }

    /** @param array<int, string|int> $cards @return list<int> */
    public static function toPoints(array $cards): array
    {
        $points = [];
        foreach ($cards as $c) {
            $points[] = self::toPoint($c);
        }

        return $points;
    }

    /** @param array<int, int|string> $cards */
    public static function sortCards(array &$cards): void
    {
        usort($cards, static function ($a, $b): int {
            $pa = self::toPoint($a);
            $pb = self::toPoint($b);

            return (self::$weightMap[$pa] ?? $pa) <=> (self::$weightMap[$pb] ?? $pb);
        });
    }

    /** @param array<int, int|string> $cards @return array<int, int> */
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
     * 牌型识别。三不带始终能认出，是否允许打出由出牌校验限制为最后一手。
     *
     * @param array<int, int|string> $cards
     * @return array{0: string, 1: int, 2: int} [牌型, 主牌权重, 张数]
     */
    public static function analyzeCards(array $cards, bool $isLastHand = false): array
    {
        $points = self::toPoints($cards);
        if ($points === []) {
            return [self::CARD_TYPE_INVALID, 0, 0];
        }

        $countMap = self::countCardNumbers($points);
        $values = array_keys($countMap);
        $counts = array_values($countMap);
        $len = count($points);

        if ($len === 4 && count($countMap) === 1 && $counts[0] === 4) {
            return [self::CARD_TYPE_BOMB, $values[0], 4];
        }

        if ($len === 1) {
            return [self::CARD_TYPE_SINGLE, $values[0], 1];
        }

        if ($len === 2 && count($countMap) === 1 && $counts[0] === 2) {
            return [self::CARD_TYPE_PAIR, $values[0], 2];
        }

        if ($len === 3 && count($countMap) === 1 && $counts[0] === 3) {
            return [self::CARD_TYPE_TRIPLE, self::tripleCompareWeight((int) $values[0]), 3];
        }

        // 三带一：三张 + 一张单牌
        if ($len === 4 && in_array(3, $counts, true) && ! in_array(4, $counts, true)) {
            $base = (int) array_search(3, $countMap, true);
            $kickRanks = 0;
            $kickCards = 0;
            foreach ($countMap as $pt => $cnt) {
                if ((int) $pt === $base) {
                    continue;
                }
                $kickRanks++;
                $kickCards += (int) $cnt;
            }
            if ($kickRanks === 1 && $kickCards === 1) {
                return [self::CARD_TYPE_TRIPLE_ONE, self::tripleCompareWeight($base), 4];
            }
        }

        // 三带二：三张 + 一对。两张零牌不算
        if ($len === 5 && in_array(3, $counts, true) && ! in_array(4, $counts, true)) {
            $base = (int) array_search(3, $countMap, true);
            $kick = [];
            foreach ($countMap as $pt => $cnt) {
                if ((int) $pt !== $base) {
                    $kick[(int) $pt] = (int) $cnt;
                }
            }
            if (count($kick) === 1 && reset($kick) === 2) {
                return [self::CARD_TYPE_TRIPLE_TWO, self::tripleCompareWeight($base), 5];
            }
        }

        $four = self::matchFourWith($countMap, $len);
        if ($four !== null) {
            return $four;
        }

        // 顺子：≥5，不含 2
        if ($len >= 5 && count($countMap) === $len) {
            $min = min($values);
            $max = max($values);
            if ($max <= 14 && $max - $min + 1 === $len) {
                return [self::CARD_TYPE_STRAIGHT, $max, $len];
            }
        }

        // 连对：≥2 对，不含 2
        if ($len >= 4 && $len % 2 === 0) {
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
                $pairCount = intdiv($len, 2);
                if ($max <= 14 && $max - $min + 1 === $pairCount) {
                    return [self::CARD_TYPE_SEQUENCE_PAIR, $max, $len];
                }
            }
        }

        unset($isLastHand);
        $plane = self::matchPlane($countMap, $len);
        if ($plane !== null) {
            return $plane;
        }

        return [self::CARD_TYPE_INVALID, 0, $len];
    }

    /**
     * 四带二单（两张不同单牌）/ 四带两对。没有四带一、四带三。
     *
     * @param array<int, int> $countMap
     * @return array{0: string, 1: int, 2: int}|null
     */
    private static function matchFourWith(array $countMap, int $len): ?array
    {
        $base = null;
        foreach ($countMap as $point => $cnt) {
            if ((int) $cnt === 4) {
                if ($base !== null) {
                    return null;
                }
                $base = (int) $point;
            }
        }
        if ($base === null) {
            return null;
        }
        $kick = [];
        foreach ($countMap as $point => $cnt) {
            if ((int) $point === $base) {
                continue;
            }
            $kick[] = (int) $cnt;
        }
        if ($len === 6 && count($kick) === 2 && $kick[0] === 1 && $kick[1] === 1) {
            return [self::CARD_TYPE_FOUR_TWO, $base, 6];
        }
        if ($len === 8 && count($kick) === 2 && $kick[0] === 2 && $kick[1] === 2) {
            return [self::CARD_TYPE_FOUR_TWO_PAIR, $base, 8];
        }

        return null;
    }

    /**
     * 飞机：连续三条不带，或每组带一张单，或每组带一对。不能混带。
     *
     * @param array<int, int> $countMap
     * @return array{0: string, 1: int, 2: int}|null
     */
    private static function matchPlane(array $countMap, int $len): ?array
    {
        if ($countMap === []) {
            return null;
        }
        $body = [];
        foreach ($countMap as $point => $cnt) {
            $p = (int) $point;
            if ((int) $cnt >= 3 && $p >= 3 && $p <= 14) {
                $body[] = $p;
            }
        }
        sort($body);
        $best = [];
        $run = [];
        foreach ($body as $p) {
            if ($run === [] || $p === end($run) + 1) {
                $run[] = $p;
            } else {
                if (count($run) > count($best)) {
                    $best = $run;
                }
                $run = [$p];
            }
        }
        if (count($run) > count($best)) {
            $best = $run;
        }
        $runLen = count($best);
        if ($runLen < 2) {
            return null;
        }
        $inRun = array_flip($best);
        $wings = [];
        foreach ($countMap as $point => $cnt) {
            $p = (int) $point;
            $extra = isset($inRun[$p]) ? (int) $cnt - 3 : (int) $cnt;
            if ($extra < 0) {
                return null;
            }
            if ($extra > 0) {
                $wings[] = $extra;
            }
        }
        $wingCards = array_sum($wings);
        if ($len !== $runLen * 3 + $wingCards) {
            return null;
        }
        $weight = self::tripleCompareWeight((int) max($best));
        if ($wings === []) {
            return [self::CARD_TYPE_PLANE, $weight, $len];
        }
        if (count($wings) === $runLen && $wingCards === $runLen && max($wings) === 1) {
            return [self::CARD_TYPE_PLANE_SINGLE, $weight, $len];
        }
        if (count($wings) === $runLen && $wingCards === $runLen * 2 && min($wings) === 2 && max($wings) === 2) {
            return [self::CARD_TYPE_PLANE_PAIR, $weight, $len];
        }

        return null;
    }

    private static function isPlaneType(string $type): bool
    {
        return in_array($type, [
            self::CARD_TYPE_PLANE,
            self::CARD_TYPE_PLANE_SINGLE,
            self::CARD_TYPE_PLANE_PAIR,
            self::CARD_TYPE_PLANE_TAIL,
        ], true);
    }

    /** @param array<int, int|string> $cards */
    private static function planeRunLen(array $cards): int
    {
        $countMap = self::countCardNumbers(self::toPoints($cards));
        $body = [];
        foreach ($countMap as $point => $cnt) {
            $p = (int) $point;
            if ((int) $cnt >= 3 && $p >= 3 && $p <= 14) {
                $body[] = $p;
            }
        }
        sort($body);
        $best = 0;
        $run = 0;
        $prev = null;
        foreach ($body as $p) {
            if ($prev !== null && $p === $prev + 1) {
                $run++;
            } else {
                $run = 1;
            }
            $prev = $p;
            if ($run > $best) {
                $best = $run;
            }
        }

        return $best;
    }

    /**
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

        // 三个 A = 最大炸弹：压一切；任何牌（含炸弹）都压不过三个 A
        if (self::isTripleAce($curType, $curWeight, $curLen)) {
            return true;
        }
        if (self::isTripleAce($lastType, $lastWeight, $lastLen)) {
            return false;
        }

        if ($curType === self::CARD_TYPE_BOMB && $lastType !== self::CARD_TYPE_BOMB) {
            return true;
        }
        if ($lastType === self::CARD_TYPE_BOMB && $curType !== self::CARD_TYPE_BOMB) {
            return false;
        }

        if (self::isPlaneType($curType) && self::isPlaneType($lastType)) {
            $curRun = self::planeRunLen($current);
            $lastRun = self::planeRunLen($last);
            if ($curRun >= 2 && $curRun === $lastRun && $curType === $lastType) {
                return $curWeight > $lastWeight;
            }

            return false;
        }

        // 同型同张数才可比，带牌不参与比较
        if ($curType === $lastType && $curLen === $lastLen) {
            return $curWeight > $lastWeight;
        }

        return false;
    }

    /**
     * 手牌是否压得过上家（强制管牌用）
     *
     * @param list<string> $hand
     * @param list<string> $lastCards
     */
    public static function canBeat(array $hand, array $lastCards): bool
    {
        if ($lastCards === []) {
            return true;
        }

        return self::suggestPlay($hand, $lastCards) !== [];
    }

    /**
     * 托管建议出牌。
     * forceLargestSingle：下家剩 1 张时，接/出单须最大单（领出仍可出对子等）。
     * forceLargestPair：已停用。下家剩 2 张不限制，随便出。
     *
     * @param list<string> $hand
     * @param list<string> $lastCards
     * @return list<string>
     */
    public static function suggestPlay(
        array $hand,
        array $lastCards = [],
        bool $needSpade3 = false,
        bool $forceLargestSingle = false,
        bool $forceLargestPair = false,
    ): array
    {
        unset($needSpade3); // 兼容旧入参：持红桃3者先出，但不必带出红桃3
        $hand = array_values(array_filter(array_map(
            static fn ($c) => strtoupper(trim((string) $c)),
            $hand
        ), static fn (string $c): bool => $c !== ''));
        if ($hand === []) {
            return [];
        }

        if ($lastCards === []) {
            // 领出：若整手牌本身合法（含「三飞机一次出完」），直接出完
            [$allType] = self::analyzeCards($hand, true);
            if ($allType !== self::CARD_TYPE_INVALID) {
                return $hand;
            }

            return self::suggestLead($hand, $forceLargestSingle);
        }

        [$lastType, $lastWeight, $lastLen] = self::analyzeCards($lastCards);
        if ($lastType === self::CARD_TYPE_INVALID) {
            return [];
        }
        // 三个 A 无解
        if (self::isTripleAce($lastType, $lastWeight, $lastLen)) {
            return [];
        }

        $same = [];
        if ($forceLargestSingle && $lastType === self::CARD_TYPE_SINGLE) {
            $sorted = $hand;
            self::sortCards($sorted);
            $largest = [$sorted[count($sorted) - 1]];
            if (self::isBigger($largest, $lastCards)) {
                $same = $largest;
            }
        } elseif ($forceLargestPair && $lastType === self::CARD_TYPE_PAIR) {
            $largest = self::findLargestNOfAKind($hand, 2);
            if ($largest !== [] && self::isBigger($largest, $lastCards)) {
                $same = $largest;
            }
        } else {
            $same = self::findSameTypeBigger(self::withoutBombs($hand), $lastType, $lastWeight, $lastLen, $lastCards);
        }
        if ($same !== []) {
            return $same;
        }

        $minBomb = $lastType === self::CARD_TYPE_BOMB ? $lastWeight : 0;
        $bomb = self::findBombCards($hand, $minBomb);
        if ($bomb !== []) {
            return $bomb;
        }

        // 无更大炸弹时，三个 A 作为最大炸弹可管
        return self::findTripleAce($hand);
    }

    /**
     * 首出保留炸弹和三个 A，优先顺子、连对、飞机、三带一、对子，最后才出单张。
     * 下家报单时，若只能出单张，出最大单。
     *
     * @param list<string> $hand
     * @return list<string>
     */
    private static function suggestLead(array $hand, bool $forceLargestSingle): array
    {
        $soft = self::withoutBombs($hand);
        if ($soft === []) {
            $bomb = self::findBombCards($hand, 0);
            if ($bomb !== []) {
                return $bomb;
            }
            $ace = self::findTripleAce($hand);

            return $ace !== [] ? $ace : [$hand[0]];
        }

        $map = self::groupHand($soft);
        // 自由出牌：优先最长顺子 / 连对（张数更多者优先，同长取更小顶牌）
        $straight = self::bestRun($map, 1, 5, true) ?: self::bestRun($map, 1, 5, false);
        $seqPair = self::bestRun($map, 2, 2, true) ?: self::bestRun($map, 2, 2, false);
        $play = [];
        if ($straight !== [] || $seqPair !== []) {
            $play = count($seqPair) > count($straight) ? $seqPair : $straight;
        }
        if ($play === []) {
            $play = self::leadPlane($map);
        }
        if ($play === []) {
            $play = self::leadTripleOne($map);
        }
        if ($play === []) {
            $play = self::leadPair($map);
        }
        if ($play === []) {
            $sorted = $soft;
            self::sortCards($sorted);
            $play = [$sorted[0]];
        }
        if ($forceLargestSingle && count($play) === 1) {
            $play = [self::largestSingle($hand)];
        }
        if (! self::legalMidHand($play)) {
            $sorted = $hand;
            self::sortCards($sorted);

            return [$forceLargestSingle ? $sorted[count($sorted) - 1] : $sorted[0]];
        }

        return $play;
    }

    /**
     * 四张炸弹和三个 A 不拿去凑顺子、对子。
     *
     * @param list<string> $hand
     * @return list<string>
     */
    private static function withoutBombs(array $hand): array
    {
        $map = self::groupHand($hand);
        $drop = [];
        foreach ($map as $point => $codes) {
            if (count($codes) >= 4 || ((int) $point === 14 && count($codes) >= 3)) {
                $drop[(int) $point] = true;
            }
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
     * @param list<string> $play
     */
    private static function legalMidHand(array $play): bool
    {
        [$type] = self::analyzeCards($play, false);

        return in_array($type, [
            self::CARD_TYPE_SINGLE,
            self::CARD_TYPE_PAIR,
            self::CARD_TYPE_TRIPLE_ONE,
            self::CARD_TYPE_TRIPLE_TWO,
            self::CARD_TYPE_STRAIGHT,
            self::CARD_TYPE_SEQUENCE_PAIR,
            self::CARD_TYPE_PLANE_SINGLE,
            self::CARD_TYPE_PLANE_PAIR,
            self::CARD_TYPE_FOUR_TWO,
            self::CARD_TYPE_FOUR_TWO_PAIR,
            self::CARD_TYPE_BOMB,
        ], true);
    }

    /**
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

        return $best !== [] && self::legalMidHand($best) ? $best : [];
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
                        if (self::legalMidHand($play)) {
                            return $play;
                        }
                        continue 3;
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
    private static function leadTripleOne(array $map): array
    {
        foreach ($map as $point => $codes) {
            if (count($codes) < 3 || (int) $point > 14) {
                continue;
            }
            foreach ($map as $p2 => $codes2) {
                if ($p2 === $point || $codes2 === []) {
                    continue;
                }
                $play = [...array_slice($codes, 0, 3), $codes2[0]];
                if (self::legalMidHand($play)) {
                    return $play;
                }
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
            if (! self::legalMidHand($pair)) {
                continue;
            }
            if (count($codes) === 2) {
                return $pair;
            }
            if ($fallback === []) {
                $fallback = $pair;
            }
        }

        return $fallback;
    }

    /** @param list<string> $hand */
    private static function largestSingle(array $hand): string
    {
        $sorted = $hand;
        self::sortCards($sorted);

        return $sorted[count($sorted) - 1];
    }

    /**
     * @param list<string> $hand
     * @return list<string>
     */
    private static function findLargestNOfAKind(array $hand, int $n): array
    {
        $map = self::groupHand($hand);
        $best = 0;
        $codes = [];
        foreach ($map as $point => $list) {
            if (count($list) >= $n && (int) $point >= $best) {
                $best = (int) $point;
                $codes = array_slice($list, 0, $n);
            }
        }

        return $codes;
    }

    /**
     * 三个 A：压炸弹的最后手段
     *
     * @param list<string> $hand
     * @return list<string>
     */
    private static function findTripleAce(array $hand): array
    {
        if (count($hand) !== 3) {
            return [];
        }
        $aces = [];
        foreach ($hand as $card) {
            if (self::toPoint($card) === 14) {
                $aces[] = $card;
            }
        }

        return count($aces) >= 3 ? array_slice($aces, 0, 3) : [];
    }

    /** @param list<string> $hand @return array<int, list<string>> */
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
    private static function findSameTypeBigger(array $hand, string $lastType, int $lastWeight, int $lastLen, array $lastCards = []): array
    {
        $map = self::groupHand($hand);

        return match ($lastType) {
            self::CARD_TYPE_SINGLE => self::pickSingleBigger($hand, $lastWeight),
            self::CARD_TYPE_PAIR => self::pickNOfAKind($map, 2, $lastWeight),
            self::CARD_TYPE_TRIPLE => count($hand) === 3 ? self::pickNOfAKind($map, 3, $lastWeight) : [],
            self::CARD_TYPE_TRIPLE_ONE => self::pickTripleWithKicker($map, $hand, $lastWeight, 1),
            self::CARD_TYPE_TRIPLE_TWO => self::pickTripleWithKicker($map, $hand, $lastWeight, 2),
            self::CARD_TYPE_FOUR_TWO => self::pickFourWithTwoSingles($map, $hand, $lastWeight),
            self::CARD_TYPE_FOUR_TWO_PAIR => self::pickFourWithTwoPairs($map, $lastWeight),
            self::CARD_TYPE_BOMB => self::findBombCards($hand, $lastWeight),
            self::CARD_TYPE_STRAIGHT => self::pickStraight($map, $lastLen, $lastWeight, 1),
            self::CARD_TYPE_SEQUENCE_PAIR => self::pickStraight($map, intdiv($lastLen, 2), $lastWeight, 2),
            self::CARD_TYPE_PLANE,
            self::CARD_TYPE_PLANE_SINGLE,
            self::CARD_TYPE_PLANE_PAIR,
            self::CARD_TYPE_PLANE_TAIL => self::pickPlane($map, $hand, $lastType, $lastLen, $lastWeight, $lastCards),
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
            $w = $n >= 3 ? self::tripleCompareWeight((int) $point) : (int) $point;
            if ($w <= $lastWeight) {
                continue;
            }
            if (count($codes) >= $n) {
                return array_slice($codes, 0, $n);
            }
        }

        return [];
    }

    /**
     * 三带一取一张；三带二必须带一对。
     *
     * @param array<int, list<string>> $map
     * @param list<string> $hand
     * @return list<string>
     */
    private static function pickTripleWithKicker(array $map, array $hand, int $lastWeight, int $kickerCount = 2): array
    {
        if ($kickerCount < 1) {
            return [];
        }
        foreach ($map as $point => $codes) {
            if (self::tripleCompareWeight((int) $point) <= $lastWeight || count($codes) < 3) {
                continue;
            }
            $triple = array_slice($codes, 0, 3);
            if ($kickerCount === 2) {
                foreach ($map as $p2 => $codes2) {
                    if ($p2 === $point || count($codes2) < 2) {
                        continue;
                    }

                    return [...$triple, ...array_slice($codes2, 0, 2)];
                }

                continue;
            }
            $kickers = [];
            $sorted = $hand;
            self::sortCards($sorted);
            foreach ($sorted as $code) {
                if (self::toPoint($code) === $point) {
                    continue;
                }
                $kickers[] = $code;
                if (count($kickers) >= $kickerCount) {
                    return [...$triple, ...$kickers];
                }
            }
        }

        return [];
    }

    /**
     * 四带二单：两张不同点数的单牌
     *
     * @param array<int, list<string>> $map
     * @param list<string> $hand
     * @return list<string>
     */
    private static function pickFourWithTwoSingles(array $map, array $hand, int $lastWeight): array
    {
        foreach ($map as $point => $codes) {
            if ((int) $point <= $lastWeight || count($codes) < 4) {
                continue;
            }
            $four = array_slice($codes, 0, 4);
            $taken = [];
            $singles = [];
            $sorted = $hand;
            self::sortCards($sorted);
            foreach ($sorted as $code) {
                $pt = self::toPoint($code);
                if ($pt === (int) $point || isset($taken[$pt])) {
                    continue;
                }
                $singles[] = $code;
                $taken[$pt] = true;
                if (count($singles) === 2) {
                    return [...$four, ...$singles];
                }
            }
        }

        return [];
    }

    private static function pickFourWith(array $map, array $hand, int $lastWeight, int $kickerCount): array
    {
        foreach ($map as $point => $codes) {
            if ((int) $point <= $lastWeight || count($codes) < 4) {
                continue;
            }
            $four = array_slice($codes, 0, 4);
            $kickers = [];
            $sorted = $hand;
            self::sortCards($sorted);
            foreach ($sorted as $code) {
                if (self::toPoint($code) === (int) $point) {
                    continue;
                }
                $kickers[] = $code;
                if (count($kickers) >= $kickerCount) {
                    return [...$four, ...$kickers];
                }
            }
        }

        return [];
    }

    /**
     * @param array<int, list<string>> $map
     * @return list<string>
     */
    private static function pickFourWithTwoPairs(array $map, int $lastWeight): array
    {
        foreach ($map as $point => $codes) {
            if ((int) $point <= $lastWeight || count($codes) < 4) {
                continue;
            }
            $four = array_slice($codes, 0, 4);
            $pairs = [];
            foreach ($map as $p2 => $codes2) {
                if ($p2 === $point || count($codes2) < 2) {
                    continue;
                }
                array_push($pairs, ...array_slice($codes2, 0, 2));
                if (count($pairs) === 4) {
                    return [...$four, ...$pairs];
                }
            }
        }

        return [];
    }

    /**
     * @param array<int, list<string>> $map
     * @return list<string>
     */
    private static function pickStraight(array $map, int $runLen, int $lastWeight, int $needPer): array
    {
        if ($runLen < 1) {
            return [];
        }
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
     * @param array<int, list<string>> $map
     * @param list<string> $hand
     * @return list<string>
     */
    private static function pickPlane(array $map, array $hand, string $lastType, int $lastLen, int $lastWeight, array $lastCards = []): array
    {
        [$wholeType, $wholeWeight] = self::analyzeCards($hand, true);
        $lastRun = $lastCards !== [] ? self::planeRunLen($lastCards) : 0;
        if ($lastRun < 2) {
            $lastRun = match ($lastType) {
                self::CARD_TYPE_PLANE_SINGLE => intdiv($lastLen, 4),
                self::CARD_TYPE_PLANE_PAIR => intdiv($lastLen, 5),
                self::CARD_TYPE_PLANE => intdiv($lastLen, 3),
                default => 0,
            };
        }
        if (
            self::isPlaneType($wholeType)
            && $lastRun >= 2
            && self::planeRunLen($hand) === $lastRun
            && $wholeWeight > $lastWeight
            && $wholeType === $lastType
        ) {
            return $hand;
        }
        $wing = match ($lastType) {
            self::CARD_TYPE_PLANE_SINGLE => 'single',
            self::CARD_TYPE_PLANE_PAIR => 'pair',
            default => 'none',
        };
        $runLen = match ($wing) {
            'single' => intdiv($lastLen, 4),
            'pair' => intdiv($lastLen, 5),
            default => intdiv($lastLen, 3),
        };
        if ($runLen < 2) {
            return [];
        }

        for ($max = 3 + $runLen - 1; $max <= 14; $max++) {
            if (self::tripleCompareWeight($max) <= $lastWeight) {
                continue;
            }
            $min = $max - $runLen + 1;
            $ok = true;
            $main = [];
            for ($p = $min; $p <= $max; $p++) {
                if (! isset($map[$p]) || count($map[$p]) < 3) {
                    $ok = false;
                    break;
                }
                array_push($main, ...array_slice($map[$p], 0, 3));
            }
            if (! $ok) {
                continue;
            }
            if ($wing === 'none') {
                if (count($hand) === count($main)) {
                    return $main;
                }
                continue;
            }
            if ($wing === 'single') {
                $used = array_fill_keys($main, true);
                $taken = [];
                $singles = [];
                $sorted = $hand;
                self::sortCards($sorted);
                foreach ($sorted as $code) {
                    if (isset($used[$code])) {
                        unset($used[$code]);
                        continue;
                    }
                    $pt = self::toPoint($code);
                    if (isset($taken[$pt])) {
                        continue;
                    }
                    $singles[] = $code;
                    $taken[$pt] = true;
                    if (count($singles) === $runLen) {
                        return array_merge($main, $singles);
                    }
                }
                continue;
            }
            $pairs = [];
            foreach ($map as $pk => $codes) {
                $pk = (int) $pk;
                $usedInBody = ($pk >= $min && $pk <= $max) ? 3 : 0;
                if (count($codes) - $usedInBody < 2) {
                    continue;
                }
                array_push($pairs, ...array_slice($codes, $usedInBody, 2));
                if (count($pairs) === $runLen * 2) {
                    return array_merge($main, $pairs);
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
}
