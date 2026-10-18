<?php

declare(strict_types=1);

namespace App\Game\Hzmj\Rule;

use App\Game\Hzmj\HzmjConsts;

/**
 * 奖码（摸宝）。结算展示对齐 game 红中麻将 SettlementPanel。
 * 家数按实际在座人数。叫分（庄倍数）是后加规则，在入账时与底分一起乘。
 */
final class LuckyTiles
{
    /** 中码牌面：一五九万条筒 + 红中 */
    public const HIT_SET = [1, 5, 9, 31, 35, 39, 51, 55, 59, HzmjConsts::KING_TILE];

    /**
     * 从牌墙翻奖码。
     *
     * @param list<int> $wall
     * @param list<int> $winHand 胡牌手牌（用于判断是否无红中加码）
     * @return array{0: list<int>, 1: int, 2: list<int>} [luckyTiles, hit, wallRemain]
     */
    public static function draw(array $wall, array $winHand, int $luckyNum): array
    {
        $wall = array_values(array_map('intval', $wall));
        $luckyNum = max(0, $luckyNum);

        if ($luckyNum <= 0) {
            return [[], 0, $wall];
        }

        // 一码全中：翻 1 张，红中计 10，否则取牌面个位
        if ($luckyNum === 1) {
            if ($wall === []) {
                return [[], 0, $wall];
            }
            $final = (int) array_shift($wall);
            $hit = $final === HzmjConsts::KING_TILE ? 10 : ($final % 10);

            return [[$final], $hit, $wall];
        }

        // 胡牌手牌无红中则多翻一张
        $hasRed = false;
        foreach ($winHand as $t) {
            if ((int) $t === HzmjConsts::KING_TILE) {
                $hasRed = true;
                break;
            }
        }
        if (! $hasRed) {
            ++$luckyNum;
        }

        $n = min(count($wall), $luckyNum);
        $see = [];
        $hit = 0;
        $hitSet = array_fill_keys(self::HIT_SET, true);
        for ($i = 0; $i < $n; ++$i) {
            $t = (int) array_shift($wall);
            $see[] = $t;
            if (isset($hitSet[$t])) {
                ++$hit;
            }
        }

        return [$see, $hit, $wall];
    }

    /**
     * 自摸奖码分：赢家 +(unit*(人数-1))，其余各 -unit。
     * unit：一码全中 = hit+2；多码 = 2*(hit+1)
     *
     * @param list<int> $seats 有人的座位号
     * @return array<string, int> seat => delta
     */
    public static function scoreDeltas(int $winSeat, int $hit, int $cfgLuckyNum, array $seats): array
    {
        $seats = array_values(array_unique(array_map('intval', $seats)));
        $others = array_values(array_filter($seats, static fn (int $s): bool => $s !== $winSeat));
        $mult = max(1, count($others));

        if ($cfgLuckyNum === 1) {
            $unit = $hit + 2;
        } else {
            $unit = 2 * ($hit + 1);
        }

        $out = [];
        foreach ($seats as $s) {
            $out[(string) $s] = 0;
        }
        $out[(string) $winSeat] = $unit * $mult;
        foreach ($others as $s) {
            $out[(string) $s] = -$unit;
        }

        return $out;
    }

    /**
     * 杠分增量。明杠双方各 3，续杠其余每家 1，暗杠其余每家 2，再乘底分和叫分。
     *
     * @param 'concealed'|'continue'|'exposed' $kongType
     * @param list<int> $seats 有人座位
     * @return array<string, int> seat => delta
     */
    public static function kongScoreDeltas(string $kongType, int $actorSeat, int $fromSeat, array $seats): array
    {
        $seats = array_values(array_unique(array_map('intval', $seats)));
        $out = [];
        foreach ($seats as $s) {
            $out[(string) $s] = 0;
        }

        if ($kongType === 'concealed') {
            // 暗杠 +6 / 各 -2
            $others = array_values(array_filter($seats, static fn (int $s): bool => $s !== $actorSeat));
            $out[(string) $actorSeat] = 2 * max(1, count($others));
            foreach ($others as $s) {
                $out[(string) $s] = -2;
            }

            return $out;
        }

        if ($kongType === 'continue') {
            // 续杠 +3 / 各 -1
            $others = array_values(array_filter($seats, static fn (int $s): bool => $s !== $actorSeat));
            $out[(string) $actorSeat] = max(1, count($others));
            foreach ($others as $s) {
                $out[(string) $s] = -1;
            }

            return $out;
        }

        // 明杠：杠者 +3，放杠者 -3
        if ($fromSeat >= 0 && $fromSeat !== $actorSeat) {
            $out[(string) $actorSeat] = 3;
            $out[(string) $fromSeat] = -3;
        }

        return $out;
    }

    /**
     * 抢杠胡：按实际其余人数计一份，全部由被抢杠的人出。
     * 两人局其余 1 家，三人局 2 家，四人局 3 家。
     *
     * @return array<string, int>
     */
    public static function kongWinScoreDeltas(int $winSeat, int $fromSeat, int $hit, int $cfgLuckyNum, array $seats): array
    {
        $seats = array_values(array_unique(array_map('intval', $seats)));
        $out = [];
        foreach ($seats as $s) {
            $out[(string) $s] = 0;
        }
        if ($cfgLuckyNum === 1) {
            $unit = $hit + 2;
        } else {
            $unit = 2 * ($hit + 1);
        }
        $houses = max(1, count(array_filter($seats, static fn (int $s): bool => $s !== $winSeat)));
        $pay = $unit * $houses;
        $out[(string) $winSeat] = $pay;
        if ($fromSeat >= 0) {
            $out[(string) $fromSeat] = (int) ($out[(string) $fromSeat] ?? 0) - $pay;
        }

        return $out;
    }
}
