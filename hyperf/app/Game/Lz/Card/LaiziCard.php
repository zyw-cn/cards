<?php

declare(strict_types=1);

namespace App\Game\Lz\Card;

use App\Game\Card\CardCode;
use App\Game\Lz\LzConsts;

/**
 * 癞子斗地主牌型识别与比较
 *
 * 编码：A/B/C/D+3~15；E16/E17 王；F55 癞子（兼容 W55）。
 * 癞子可替代 3~2 任意点数。不能单出，也不能和王出现在同一手。
 * 软炸（含癞子）小于同点或更大点的硬炸；王炸最大。
 */
class LaiziCard
{
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

    /** 手牌排序时癞子靠后（不能单出，此权重不参与比牌） */
    public const WILD_SINGLE_WEIGHT = 18;

    private static array $weightMap = [
        3 => 3, 4 => 4, 5 => 5, 6 => 6, 7 => 7, 8 => 8, 9 => 9, 10 => 10,
        11 => 11, 12 => 12, 13 => 13, 14 => 14, 15 => 15, 16 => 16, 17 => 17,
        18 => 18,
    ];

    public static function isWild(string|int $card): bool
    {
        if (is_int($card)) {
            return $card === 55;
        }

        return CardCode::normalize($card) === LzConsts::WILD_CARD;
    }

    public static function toPoint(string|int $card): int
    {
        if (is_int($card)) {
            return $card === 55 ? 55 : $card;
        }
        $card = CardCode::normalize($card);
        if ($card === '' || $card === LzConsts::WILD_CARD) {
            return 55;
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
            $pa = self::isWild($a) ? self::WILD_SINGLE_WEIGHT : self::toPoint($a);
            $pb = self::isWild($b) ? self::WILD_SINGLE_WEIGHT : self::toPoint($b);

            return (self::$weightMap[$pa] ?? $pa) <=> (self::$weightMap[$pb] ?? $pb);
        });
    }

    /**
     * @param array<int, int|string> $cards
     * @return array{0: string, 1: int, 2: int, 3?: bool} [牌型, 主牌权重, 张数, 是否软炸]
     */
    public static function analyzeCards(array $cards): array
    {
        $cards = array_values($cards);
        $len = count($cards);
        if ($len === 0) {
            return [self::CARD_TYPE_INVALID, 0, 0, false];
        }

        $wilds = 0;
        $normals = [];
        foreach ($cards as $c) {
            if (self::isWild($c)) {
                $wilds++;
            } else {
                $normals[] = self::toPoint($c);
            }
        }

        // 王炸：仅大小王，癞子不能参与
        if ($len === 2 && $wilds === 0
            && in_array(16, $normals, true) && in_array(17, $normals, true)
        ) {
            return [self::CARD_TYPE_ROCKET, 17, 2, false];
        }

        // 不能单出癞子
        if ($len === 1 && $wilds === 1) {
            return [self::CARD_TYPE_INVALID, 0, 1, false];
        }

        // 癞子不能和王配在同一手；3~2 都可以配
        if ($wilds > 0 && (in_array(16, $normals, true) || in_array(17, $normals, true))) {
            return [self::CARD_TYPE_INVALID, 0, $len, false];
        }

        if ($wilds === 0) {
            return self::analyzeNatural($normals, $len);
        }

        // 有癞子：枚举替代点，选最优合法牌型
        $best = [self::CARD_TYPE_INVALID, 0, $len, true];
        foreach (self::wildAssignments($normals, $wilds) as $expanded) {
            $cand = self::analyzeNatural($expanded, $len);
            if ($cand[0] === self::CARD_TYPE_INVALID) {
                continue;
            }
            // 含癞子的炸弹一律标软炸
            if ($cand[0] === self::CARD_TYPE_BOMB) {
                $cand[3] = true;
            } else {
                $cand[3] = false;
            }
            if (self::isBetterInterpretation($cand, $best)) {
                $best = $cand;
            }
        }

        return $best;
    }

    /**
     * @param list<int> $normals
     * @return list<list<int>>
     */
    private static function wildAssignments(array $normals, int $wilds): array
    {
        if ($wilds <= 0) {
            return [$normals];
        }
        // 本玩法整副仅 1 张癞子
        $out = [];
        for ($p = 3; $p <= 15; $p++) {
            $try = $normals;
            for ($i = 0; $i < $wilds; $i++) {
                $try[] = $p;
            }
            $out[] = $try;
        }

        return $out;
    }

    /**
     * @param array{0:string,1:int,2:int,3?:bool} $a
     * @param array{0:string,1:int,2:int,3?:bool} $b
     */
    private static function isBetterInterpretation(array $a, array $b): bool
    {
        if ($b[0] === self::CARD_TYPE_INVALID) {
            return true;
        }
        $rank = static function (string $type): int {
            return match ($type) {
                self::CARD_TYPE_ROCKET => 100,
                self::CARD_TYPE_BOMB => 90,
                self::CARD_TYPE_PLANE => 80,
                self::CARD_TYPE_STRAIGHT => 70,
                self::CARD_TYPE_SEQUENCE_PAIR => 60,
                self::CARD_TYPE_FOUR_TWO_PAIR => 55,
                self::CARD_TYPE_FOUR_TWO => 52,
                self::CARD_TYPE_TRIPLE_TWO => 50,
                self::CARD_TYPE_TRIPLE_ONE => 40,
                self::CARD_TYPE_TRIPLE => 30,
                self::CARD_TYPE_PAIR => 20,
                self::CARD_TYPE_SINGLE => 10,
                default => 0,
            };
        };
        $ra = $rank($a[0]);
        $rb = $rank($b[0]);
        if ($ra !== $rb) {
            return $ra > $rb;
        }

        return $a[1] > $b[1];
    }

    /**
     * 无癞子（或已展开）的自然牌型分析
     *
     * @param list<int> $points
     * @return array{0:string,1:int,2:int,3:bool}
     */
    private static function analyzeNatural(array $points, int $len): array
    {
        if ($points === []) {
            return [self::CARD_TYPE_INVALID, 0, $len, false];
        }
        $countMap = [];
        foreach ($points as $p) {
            $countMap[$p] = ($countMap[$p] ?? 0) + 1;
        }
        ksort($countMap);
        $values = array_keys($countMap);
        $counts = array_values($countMap);
        $maxCount = max($counts);

        if ($len === 2 && in_array(16, $points, true) && in_array(17, $points, true)) {
            return [self::CARD_TYPE_ROCKET, 17, 2, false];
        }

        if ($len === 4 && count($countMap) === 1 && $maxCount === 4) {
            return [self::CARD_TYPE_BOMB, $values[0], 4, false];
        }

        if ($len === 1) {
            return [self::CARD_TYPE_SINGLE, $values[0], 1, false];
        }
        if ($len === 2 && count($countMap) === 1) {
            return [self::CARD_TYPE_PAIR, $values[0], 2, false];
        }
        if ($len === 3 && count($countMap) === 1) {
            return [self::CARD_TYPE_TRIPLE, $values[0], 3, false];
        }

        // 三带一
        if ($len === 4 && in_array(3, $counts, true) && in_array(1, $counts, true)) {
            $base = (int) array_search(3, $countMap, true);

            return [self::CARD_TYPE_TRIPLE_ONE, $base, 4, false];
        }
        // 三带二
        if ($len === 5 && in_array(3, $counts, true) && in_array(2, $counts, true)) {
            $base = (int) array_search(3, $countMap, true);

            return [self::CARD_TYPE_TRIPLE_TWO, $base, 5, false];
        }

        // 四带二：四张 + 两张（两单或一对）
        if ($len === 6 && $maxCount === 4) {
            $base = (int) array_search(4, $countMap, true);

            return [self::CARD_TYPE_FOUR_TWO, $base, 6, false];
        }

        // 四带两对：四张 + 两个对子
        if ($len === 8 && $maxCount === 4) {
            $base = (int) array_search(4, $countMap, true);
            $pairCount = 0;
            $ok = true;
            foreach ($countMap as $cnt) {
                if ((int) $cnt === 2) {
                    $pairCount++;
                } elseif ((int) $cnt !== 4) {
                    $ok = false;
                    break;
                }
            }
            if ($ok && $pairCount === 2) {
                return [self::CARD_TYPE_FOUR_TWO_PAIR, $base, 8, false];
            }
        }

        // 顺子：≥5，不含 2/王
        if ($len >= 5 && count($countMap) === $len && $maxCount === 1) {
            $min = min($values);
            $max = max($values);
            if ($max <= 14 && $max - $min + 1 === $len) {
                return [self::CARD_TYPE_STRAIGHT, $max, $len, false];
            }
        }

        // 连对：≥3 对，不含 2/王
        if ($len >= 6 && $len % 2 === 0 && $counts === array_fill(0, count($counts), 2)) {
            $min = min($values);
            $max = max($values);
            $pairCount = intdiv($len, 2);
            if ($max <= 14 && $max - $min + 1 === $pairCount) {
                return [self::CARD_TYPE_SEQUENCE_PAIR, $max, $len, false];
            }
        }

        // 飞机（纯 / 带单 / 带对）
        if ($maxCount >= 3) {
            $triples = [];
            foreach ($countMap as $pt => $cnt) {
                if ($cnt >= 3) {
                    $triples[] = (int) $pt;
                }
            }
            sort($triples);
            $tLen = count($triples);
            if ($tLen >= 2) {
                // 找最长连续三段
                for ($span = $tLen; $span >= 2; $span--) {
                    for ($i = 0; $i <= $tLen - $span; $i++) {
                        $slice = array_slice($triples, $i, $span);
                        if (max($slice) > 14) {
                            continue;
                        }
                        if (max($slice) - min($slice) + 1 !== $span) {
                            continue;
                        }
                        $need = $span * 3;
                        if ($len === $need) {
                            return [self::CARD_TYPE_PLANE, max($slice), $len, false];
                        }
                        if ($len === $span * 4 || $len === $span * 5) {
                            return [self::CARD_TYPE_PLANE, max($slice), $len, false];
                        }
                    }
                }
            }
        }

        return [self::CARD_TYPE_INVALID, 0, $len, false];
    }

    /**
     * @param array<int, int|string> $current
     * @param array<int, int|string> $last
     */
    public static function isBigger(array $current, array $last): bool
    {
        if ($last === []) {
            return self::analyzeCards($current)[0] !== self::CARD_TYPE_INVALID;
        }

        $cur = self::analyzeCards($current);
        $prev = self::analyzeCards($last);
        if ($cur[0] === self::CARD_TYPE_INVALID) {
            return false;
        }
        if ($prev[0] === self::CARD_TYPE_INVALID) {
            return true;
        }

        $curSoft = ! empty($cur[3]);
        $prevSoft = ! empty($prev[3]);

        if ($cur[0] === self::CARD_TYPE_ROCKET) {
            return true;
        }
        if ($prev[0] === self::CARD_TYPE_ROCKET) {
            return false;
        }

        // 炸弹 vs 非炸弹
        if ($cur[0] === self::CARD_TYPE_BOMB && $prev[0] !== self::CARD_TYPE_BOMB) {
            return true;
        }
        if ($prev[0] === self::CARD_TYPE_BOMB && $cur[0] !== self::CARD_TYPE_BOMB) {
            return false;
        }

        // 炸弹间：先比点数，同点时硬炸大于软炸
        if ($cur[0] === self::CARD_TYPE_BOMB && $prev[0] === self::CARD_TYPE_BOMB) {
            if ($cur[1] !== $prev[1]) {
                return $cur[1] > $prev[1];
            }

            return ! $curSoft && $prevSoft;
        }

        if ($cur[0] === $prev[0] && $cur[2] === $prev[2]) {
            return $cur[1] > $prev[1];
        }

        return false;
    }

    /**
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
     * @param list<string> $hand
     * @param list<string> $lastCards
     * @return list<string>
     */
    /**
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

        [$lastType] = self::analyzeCards($lastCards);
        if ($lastType === self::CARD_TYPE_INVALID || $lastType === self::CARD_TYPE_ROCKET) {
            return [];
        }

        $allowBomb = $threat || $lastType === self::CARD_TYPE_BOMB;

        return self::suggestBeatByCost($hand, $lastCards, $allowBomb);
    }

    /**
     * 管牌穷举：代价顺序 硬牌 → 软牌 → 软炸 → 硬炸 → 王炸（同档取最小主牌权重）。
     * 软牌=含癞子的非炸；硬牌=不含癞子的非炸。癞子变牌由 analyzeCards 穷举。
     *
     * @param list<string> $hand
     * @param list<string> $lastCards
     * @return list<string>
     */
    public static function suggestBeatByCost(array $hand, array $lastCards, bool $allowBomb = true): array
    {
        $hand = array_values(array_filter(array_map(
            static fn ($c) => strtoupper(trim((string) $c)),
            $hand
        ), static fn (string $c): bool => $c !== ''));
        if ($hand === [] || $lastCards === []) {
            return [];
        }

        [$lastType] = self::analyzeCards($lastCards);
        if ($lastType === self::CARD_TYPE_INVALID || $lastType === self::CARD_TYPE_ROCKET) {
            return [];
        }

        $lastLen = count($lastCards);
        $best = [];
        $bestScore = PHP_INT_MAX;
        $n = count($hand);

        $lengths = [$lastLen];
        if ($allowBomb) {
            $lengths[] = 4;
            $lengths[] = 2; // 王炸
        }
        $lengths = array_values(array_unique($lengths));

        foreach ($lengths as $need) {
            if ($need <= 0 || $need > $n || $need > 8) {
                continue;
            }
            self::enumerateSubsets($hand, $need, 0, [], static function (array $subset) use (
                $lastCards,
                $allowBomb,
                &$best,
                &$bestScore
            ): void {
                if (! self::isBigger($subset, $lastCards)) {
                    return;
                }
                $info = self::analyzeCards($subset);
                if ($info[0] === self::CARD_TYPE_INVALID) {
                    return;
                }
                if (! $allowBomb && ($info[0] === self::CARD_TYPE_BOMB || $info[0] === self::CARD_TYPE_ROCKET)) {
                    return;
                }
                $score = self::beatCostScore($subset, $info);
                if ($score < $bestScore) {
                    $bestScore = $score;
                    $best = $subset;
                }
            });
        }

        return $best;
    }

    /**
     * @param list<string> $subset
     * @param array{0:string,1:int,2:int,3?:bool} $info
     */
    private static function beatCostScore(array $subset, array $info): int
    {
        $type = $info[0];
        $weight = (int) $info[1];
        $softBomb = ! empty($info[3]);
        $hasWild = false;
        foreach ($subset as $c) {
            if (self::isWild($c)) {
                $hasWild = true;
                break;
            }
        }

        // 1硬牌 2软牌 3软炸 4硬炸 5王炸
        $tier = match (true) {
            $type === self::CARD_TYPE_ROCKET => 5,
            $type === self::CARD_TYPE_BOMB && ! $softBomb => 4,
            $type === self::CARD_TYPE_BOMB && $softBomb => 3,
            $hasWild => 2,
            default => 1,
        };

        return $tier * 1_000_000 + $weight * 100 + count($subset);
    }

    /**
     * 首出：癞子不单出。先出自然牌组成的顺子、连对、三带、对子，再用一张癞子去补最短的顺子。
     *
     * @param list<string> $hand
     * @return list<string>
     */
    private static function suggestLead(array $hand): array
    {
        $wilds = [];
        $natural = [];
        foreach ($hand as $code) {
            if (self::isWild($code)) {
                $wilds[] = $code;
            } else {
                $natural[] = $code;
            }
        }
        $soft = self::withoutBombs($natural);
        $map = self::groupPoints($soft);

        $straight = self::bestRun($map, 1, 5, true) ?: self::bestRun($map, 1, 5, false);
        if ($straight === [] && $wilds !== []) {
            $straight = self::straightWithOneWild($map, $wilds[0]);
        }
        if ($straight !== []) {
            return $straight;
        }
        $seq = self::bestRun($map, 2, 3, true) ?: self::bestRun($map, 2, 3, false);
        if ($seq !== []) {
            return $seq;
        }
        $triple = self::leadTriple($map);
        if ($triple !== []) {
            return $triple;
        }
        $pair = self::leadPair($map);
        if ($pair !== []) {
            return $pair;
        }
        if ($soft !== []) {
            $sorted = $soft;
            self::sortCards($sorted);
            foreach ($sorted as $code) {
                if (self::toPoint($code) >= 16) {
                    continue;
                }
                if (self::analyzeCards([$code])[0] !== self::CARD_TYPE_INVALID) {
                    return [$code];
                }
            }
            if (self::analyzeCards([$sorted[0]])[0] !== self::CARD_TYPE_INVALID) {
                return [$sorted[0]];
            }
        }
        $bomb = self::smallestBomb($natural);
        if ($bomb !== []) {
            return $bomb;
        }
        if (count($wilds) >= 2) {
            $pair = array_slice($wilds, 0, 2);
            if (self::analyzeCards($pair)[0] !== self::CARD_TYPE_INVALID) {
                return $pair;
            }
        }

        return [];
    }

    /**
     * @param list<string> $hand
     * @return list<string>
     */
    private static function withoutBombs(array $hand): array
    {
        $map = self::groupPoints($hand);
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
     * @param list<string> $hand
     * @return array<int, list<string>>
     */
    private static function groupPoints(array $hand): array
    {
        $map = [];
        foreach ($hand as $code) {
            if (self::isWild($code)) {
                continue;
            }
            $p = self::toPoint($code);
            if ($p > 0) {
                $map[$p][] = $code;
            }
        }
        ksort($map);

        return $map;
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

        return $best;
    }

    /**
     * 用一张癞子补 3~A 里缺一张的五张顺。
     *
     * @param array<int, list<string>> $map
     * @return list<string>
     */
    private static function straightWithOneWild(array $map, string $wild): array
    {
        for ($start = 3; $start <= 10; $start++) {
            $missing = 0;
            $picked = [];
            for ($p = $start; $p < $start + 5; $p++) {
                if (! isset($map[$p]) || $map[$p] === []) {
                    $missing++;
                    continue;
                }
                $picked[] = $map[$p][0];
            }
            if ($missing !== 1) {
                continue;
            }
            $play = [...$picked, $wild];
            if (self::analyzeCards($play)[0] === self::CARD_TYPE_STRAIGHT) {
                return $play;
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
            $play = [...array_slice($codes, 0, 3), $kicker];
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
     * @param list<string> $natural
     * @return list<string>
     */
    private static function smallestBomb(array $natural): array
    {
        $map = self::groupPoints($natural);
        foreach ($map as $codes) {
            if (count($codes) >= 4) {
                return array_slice($codes, 0, 4);
            }
        }

        return [];
    }

    /**
     * @param list<string> $hand
     * @param list<string> $picked
     * @param callable(list<string>):void $visitor
     */
    private static function enumerateSubsets(
        array $hand,
        int $need,
        int $start,
        array $picked,
        callable $visitor,
    ): void {
        if (count($picked) === $need) {
            $visitor($picked);

            return;
        }
        $left = $need - count($picked);
        $n = count($hand);
        for ($i = $start; $i <= $n - $left; $i++) {
            $picked[] = $hand[$i];
            self::enumerateSubsets($hand, $need, $i + 1, $picked, $visitor);
            array_pop($picked);
        }
    }
}
