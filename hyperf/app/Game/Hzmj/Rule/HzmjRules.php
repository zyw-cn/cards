<?php

declare(strict_types=1);

namespace App\Game\Hzmj\Rule;

use App\Game\Hzmj\HzmjConsts;

/**
 * 红中麻将规则纯函数（移植自前端 meld.js / ops.js）
 *
 * @phpstan-type TileList list<int>
 * @phpstan-type UpMeld list<int>
 * @phpstan-type UpList list<UpMeld>
 */
final class HzmjRules
{
    /**
     * @param TileList $tiles
     */
    public static function histKey(array $tiles): string
    {
        $sorted = $tiles;
        sort($sorted);

        return implode(',', $sorted);
    }

    /**
     * @param TileList $tiles
     */
    public static function countOf(array $tiles, int $t): int
    {
        $n = 0;
        foreach ($tiles as $x) {
            if ((int) $x === $t) {
                ++$n;
            }
        }

        return $n;
    }

    /**
     * @param TileList $tiles
     * @return TileList
     */
    public static function removeOne(array $tiles, int $t): array
    {
        $i = array_search($t, $tiles, true);
        if ($i === false) {
            return $tiles;
        }
        $next = $tiles;
        array_splice($next, (int) $i, 1);

        return array_values($next);
    }

    /**
     * @param TileList $hand
     * @return TileList
     */
    public static function removeTiles(array $hand, int $t, int $n): array
    {
        $next = array_values($hand);
        $left = $n;
        for ($i = count($next) - 1; $i >= 0 && $left > 0; --$i) {
            if ((int) $next[$i] === $t) {
                array_splice($next, $i, 1);
                --$left;
            }
        }

        return array_values($next);
    }

    /**
     * 癞子优先排序
     *
     * @param TileList $tiles
     * @return TileList
     */
    public static function sortTiles(array $tiles, int $king = HzmjConsts::KING_TILE): array
    {
        $out = array_map('intval', $tiles);
        usort($out, static function (int $a, int $b) use ($king): int {
            $ak = $a === $king ? -1 : $a;
            $bk = $b === $king ? -1 : $b;

            return $ak <=> $bk;
        });

        return $out;
    }

    /**
     * @param TileList $tiles
     * @return array{chars: TileList, bambs: TileList, dots: TileList, dragonRed: int}
     */
    public static function classifySuitTiles(array $tiles): array
    {
        $chars = [];
        $bambs = [];
        $dots = [];
        $dragonRed = 0;
        $charSet = array_flip(HzmjConsts::CHARACTER);
        $bambSet = array_flip(HzmjConsts::BAMBOO);
        $dotSet = array_flip(HzmjConsts::DOT);
        foreach ($tiles as $t) {
            $t = (int) $t;
            if (isset($charSet[$t])) {
                $chars[] = $t;
            } elseif (isset($bambSet[$t])) {
                $bambs[] = $t;
            } elseif (isset($dotSet[$t])) {
                $dots[] = $t;
            } elseif ($t === HzmjConsts::KING_TILE) {
                ++$dragonRed;
            }
        }

        return [
            'chars'     => $chars,
            'bambs'     => $bambs,
            'dots'      => $dots,
            'dragonRed' => $dragonRed,
        ];
    }

    /**
     * 仅面子缺口数（无将）
     *
     * @param TileList $tilesIn
     * @param array<string, int> $history
     */
    public static function meldOnlyNeedNum(array $tilesIn, array &$history = [], int $used = 0): int
    {
        if ($used > 4) {
            return 999;
        }
        $tiles = $tilesIn;
        sort($tiles);
        $key = self::histKey($tiles);
        if (isset($history[$key])) {
            return $history[$key];
        }
        $size = count($tiles);
        if ($size === 0) {
            return 0;
        }
        if ($size === 1) {
            return 2;
        }
        if ($size === 2) {
            $p1 = (int) $tiles[0];
            $p2 = (int) $tiles[1];
            $case12 = 999;
            if ($p1 < HzmjConsts::BOUNDARY && ($p2 - $p1) <= 2) {
                $case12 = 1;
            }
            $case22 = $p1 === $p2 ? 1 : 4;

            return min($case12, $case22);
        }

        $first = (int) $tiles[0];
        $case1 = 0;
        $left1 = array_slice($tiles, 1);
        if ($first >= HzmjConsts::BOUNDARY) {
            $case1 = 999;
        } else {
            if (in_array($first + 1, $left1, true)) {
                $left1 = self::removeOne($left1, $first + 1);
            } else {
                ++$case1;
            }
            if (in_array($first + 2, $left1, true)) {
                $left1 = self::removeOne($left1, $first + 2);
            } else {
                ++$case1;
            }
            $res1 = self::meldOnlyNeedNum($left1, $history, $used);
            $history[self::histKey($left1)] = $res1;
            $case1 += $res1;
        }

        $case2 = 0;
        $left2 = array_slice($tiles, 1);
        $cnt = self::countOf($left2, $first);
        if ($cnt >= 2) {
            $left2 = self::removeOne($left2, $first);
            $left2 = self::removeOne($left2, $first);
        } elseif ($cnt === 1) {
            $left2 = self::removeOne($left2, $first);
            ++$case2;
        } else {
            $case2 += 2;
        }
        $res2 = self::meldOnlyNeedNum($left2, $history, $used);
        $history[self::histKey($left2)] = $res2;
        $case2 += $res2;

        $result = min($case1, $case2);
        $history[$key] = $result;

        return $result;
    }

    /**
     * 含将的面子缺口数
     *
     * @param TileList $tiles
     * @param array<string, int> $history
     */
    public static function meldWithPairNeedNum(array $tiles, array &$history = []): int
    {
        $case1 = 999;
        $case2 = 999;
        if (self::meldOnlyNeedNum($tiles, $history) === 0) {
            $case1 = 2;
        }
        $n = count($tiles);
        for ($i = 0; $i < $n; ++$i) {
            $t = (int) $tiles[$i];
            $tmp = $tiles;
            if (self::countOf($tiles, $t) === 1) {
                $tmp = self::removeOne($tmp, $t);
                $case2 = min($case2, 1 + self::meldOnlyNeedNum($tmp, $history));
            } else {
                $tmp = self::removeOne($tmp, $t);
                $tmp = self::removeOne($tmp, $t);
                $case2 = min($case2, self::meldOnlyNeedNum($tmp, $history));
            }
        }

        return min($case1, $case2);
    }

    /**
     * @param TileList $handTiles
     * @param list<int> $kingTiles
     */
    public static function canWin(array $handTiles, array $kingTiles = [HzmjConsts::KING_TILE]): bool
    {
        unset($kingTiles); // 红中按牌面 75 归入 dragonRed，与前端一致
        if (count($handTiles) % 3 !== 2) {
            return false;
        }
        $tiles = array_map('intval', $handTiles);
        sort($tiles);
        $classified = self::classifySuitTiles($tiles);
        $chars = $classified['chars'];
        $bambs = $classified['bambs'];
        $dots = $classified['dots'];
        $dragonRed = $classified['dragonRed'];
        $hist = [];
        $c1 = self::meldOnlyNeedNum($chars, $hist);
        $c2 = self::meldWithPairNeedNum($chars, $hist);
        if ($c1 > $dragonRed && $c2 > $dragonRed) {
            return false;
        }
        $b1 = self::meldOnlyNeedNum($bambs, $hist);
        $b2 = self::meldWithPairNeedNum($bambs, $hist);
        if ($b1 > $dragonRed && $b2 > $dragonRed) {
            return false;
        }
        $d1 = self::meldOnlyNeedNum($dots, $hist);
        $d2 = self::meldWithPairNeedNum($dots, $hist);
        if ($d1 > $dragonRed && $d2 > $dragonRed) {
            return false;
        }

        return ($c2 + $b1 + $d1 <= $dragonRed)
            || ($c1 + $b2 + $d1 <= $dragonRed)
            || ($c1 + $b1 + $d2 <= $dragonRed);
    }

    /**
     * @param TileList $tiles
     * @param list<int> $kingTiles
     */
    public static function canPong(array $tiles, int $t, array $kingTiles = [HzmjConsts::KING_TILE]): bool
    {
        if (in_array($t, $kingTiles, true)) {
            return false;
        }

        return self::countOf($tiles, $t) >= 2;
    }

    /**
     * @param TileList $tiles
     * @param list<int> $kingTiles
     */
    public static function canExposedKong(array $tiles, int $t, array $kingTiles = [HzmjConsts::KING_TILE]): bool
    {
        if (in_array($t, $kingTiles, true)) {
            return false;
        }

        return self::countOf($tiles, $t) === 3;
    }

    /**
     * @param TileList $tiles
     * @param list<int> $kingTiles
     */
    public static function canConcealedKong(array $tiles, int $t, array $kingTiles = [HzmjConsts::KING_TILE]): bool
    {
        if (in_array($t, $kingTiles, true)) {
            return false;
        }

        return self::countOf($tiles, $t) === 4;
    }

    /**
     * @param TileList $tiles
     * @param list<int> $kingTiles
     */
    public static function getOneConcealedKongTile(array $tiles, array $kingTiles = [HzmjConsts::KING_TILE]): int
    {
        $map = [];
        foreach ($tiles as $t) {
            $t = (int) $t;
            if (in_array($t, $kingTiles, true)) {
                continue;
            }
            $map[$t] = ($map[$t] ?? 0) + 1;
            if ($map[$t] >= 4) {
                return $t;
            }
        }

        return 0;
    }

    /**
     * @param UpList $upTilesList
     * @param list<int> $kingTiles
     */
    public static function canContinueKongFromUptiles(array $upTilesList, int $t, array $kingTiles = [HzmjConsts::KING_TILE]): bool
    {
        if (in_array($t, $kingTiles, true)) {
            return false;
        }
        foreach ($upTilesList as $m) {
            if (! is_array($m) || count($m) !== 3) {
                continue;
            }
            $a = (int) $m[0];
            $b = (int) $m[1];
            $c = (int) $m[2];
            if ($a === $b && $b === $c && $a === $t) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param TileList $hand
     * @param UpList $upTilesList
     * @param list<int> $kingTiles
     */
    public static function getContinueKongTile(array $hand, array $upTilesList, array $kingTiles = [HzmjConsts::KING_TILE]): int
    {
        foreach ($hand as $t) {
            $t = (int) $t;
            if (self::canContinueKongFromUptiles($upTilesList, $t, $kingTiles)) {
                return $t;
            }
        }

        return 0;
    }

    /**
     * @param TileList $hand
     * @param list<int> $kingTiles
     * @return list<int>
     */
    public static function getDiscardNotifyOps(array $hand, int $tile, array $kingTiles = [HzmjConsts::KING_TILE]): array
    {
        $ops = [];
        if (self::canExposedKong($hand, $tile, $kingTiles)) {
            $ops[] = HzmjConsts::SHOW_OP_KONG;
        }
        if (self::canPong($hand, $tile, $kingTiles)) {
            $ops[] = HzmjConsts::SHOW_OP_PONG;
        }

        return $ops;
    }

    /**
     * @param TileList $hand
     * @param UpList $upTilesList
     * @param list<int> $kingTiles
     * @return list<int>
     */
    public static function getDrawNotifyOps(array $hand, array $upTilesList, array $kingTiles = [HzmjConsts::KING_TILE]): array
    {
        $ops = [];
        if (self::getOneConcealedKongTile($hand, $kingTiles) || self::getContinueKongTile($hand, $upTilesList, $kingTiles)) {
            $ops[] = HzmjConsts::SHOW_OP_KONG;
        }
        if (count($hand) % 3 === 2 && self::canWin($hand, $kingTiles)) {
            $ops[] = HzmjConsts::SHOW_OP_WIN;
        }

        return $ops;
    }

    /**
     * @param list<string|int> $raw
     * @return TileList
     */
    /**
     * 托管出牌：打离听牌更远、又不成搭的牌。红中留在手里。
     *
     * @param TileList $hand
     * @param list<int> $kingTiles
     */
    public static function suggestDiscard(array $hand, array $kingTiles = [HzmjConsts::KING_TILE]): int
    {
        $hand = array_values(array_map('intval', $hand));
        if ($hand === []) {
            return 0;
        }
        $kings = array_flip($kingTiles);
        $uniq = [];
        foreach ($hand as $tile) {
            if (! isset($kings[$tile])) {
                $uniq[$tile] = $tile;
            }
        }
        if ($uniq === []) {
            return $hand[0];
        }

        $best = 0;
        $bestKey = PHP_INT_MAX;
        foreach ($uniq as $tile) {
            $next = self::removeOne($hand, $tile);
            $need = self::handNeed($next);
            $key = $need * 1000 - self::isolation($hand, $tile) * 10 - self::edgeBias($tile);
            if ($key < $bestKey) {
                $bestKey = $key;
                $best = $tile;
            }
        }

        return $best;
    }

    /**
     * 孤对才碰。旁边已经有搭子时留下，避免碰散牌型。
     *
     * @param TileList $hand
     */
    public static function shouldAutoPong(array $hand, int $tile, array $kingTiles = [HzmjConsts::KING_TILE]): bool
    {
        unset($kingTiles);
        $tile = (int) $tile;
        if ($tile <= 0 || $tile >= HzmjConsts::BOUNDARY || self::countOf($hand, $tile) !== 2) {
            return false;
        }
        $rank = $tile % 10;
        $suitBase = $tile - $rank;
        foreach ($hand as $other) {
            $other = (int) $other;
            if ($other === $tile || $other >= HzmjConsts::BOUNDARY) {
                continue;
            }
            if ($other - ($other % 10) !== $suitBase) {
                continue;
            }
            $gap = abs(($other % 10) - $rank);
            if ($gap >= 1 && $gap <= 2) {
                return false;
            }
        }

        return true;
    }

    /**
     * 三门凑齐将牌后还缺多少张癞子。越小越接近胡牌。
     *
     * @param TileList $tiles
     */
    private static function handNeed(array $tiles): int
    {
        $classified = self::classifySuitTiles($tiles);
        $hist = [];
        $c1 = self::meldOnlyNeedNum($classified['chars'], $hist);
        $c2 = self::meldWithPairNeedNum($classified['chars'], $hist);
        $b1 = self::meldOnlyNeedNum($classified['bambs'], $hist);
        $b2 = self::meldWithPairNeedNum($classified['bambs'], $hist);
        $d1 = self::meldOnlyNeedNum($classified['dots'], $hist);
        $d2 = self::meldWithPairNeedNum($classified['dots'], $hist);

        return min($c2 + $b1 + $d1, $c1 + $b2 + $d1, $c1 + $b1 + $d2);
    }

    /**
     * 单张且左右没有邻张时更适合打出。
     *
     * @param TileList $hand
     */
    private static function isolation(array $hand, int $tile): int
    {
        if (self::countOf($hand, $tile) !== 1 || $tile >= HzmjConsts::BOUNDARY) {
            return 0;
        }
        $rank = $tile % 10;
        $suitBase = $tile - $rank;
        foreach ($hand as $other) {
            $other = (int) $other;
            if ($other === $tile || $other >= HzmjConsts::BOUNDARY) {
                continue;
            }
            if ($other - ($other % 10) !== $suitBase) {
                continue;
            }
            if (abs(($other % 10) - $rank) <= 2) {
                return 0;
            }
        }

        return 3;
    }

    private static function edgeBias(int $tile): int
    {
        if ($tile >= HzmjConsts::BOUNDARY) {
            return 0;
        }
        $rank = $tile % 10;

        return ($rank === 1 || $rank === 9) ? 2 : (($rank === 2 || $rank === 8) ? 1 : 0);
    }

    public static function toIntTiles(array $raw): array
    {
        $out = [];
        foreach ($raw as $t) {
            $out[] = (int) $t;
        }

        return $out;
    }

    /**
     * @param TileList $tiles
     * @return list<string>
     */
    public static function toStringTiles(array $tiles): array
    {
        return array_map(static fn (int $t): string => (string) $t, $tiles);
    }
}
