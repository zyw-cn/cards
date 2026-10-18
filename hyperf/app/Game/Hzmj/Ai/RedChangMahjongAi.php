<?php

declare(strict_types=1);

namespace App\Game\Hzmj\Ai;

use App\Game\Contract\GameTrustAiInterface;
use App\Game\Contract\TrustDecision;
use App\Game\Hzmj\HzmjConsts;
use App\Game\Hzmj\Rule\HzmjRules;

/**
 * 红中麻将托管 AI
 *
 * 1. 红中万能牌绝对禁止打出
 * 2. 摸牌后优先回溯/变牌检测自摸（可胡必胡）
 * 3. 切牌顺序：字牌孤张 → 近4轮安全熟张 → 1/9边角孤张
 * 4. 尾局残局：拆搭子跟熟张，绝对防守
 */
final class RedChangMahjongAi implements GameTrustAiInterface
{
    /** 近 N 轮弃牌视为安全熟张窗口 */
    private const SAFE_ROUNDS = 4;

    /** 牌墙剩余 ≤ 此值视为尾局残局 */
    private const ENDGAME_WALL = 16;

    public function decide(string $phase, array $context = []): TrustDecision
    {
        $phase = strtolower(trim($phase));
        $hand = is_array($context['hand'] ?? null)
            ? HzmjRules::toIntTiles($context['hand'])
            : [];
        $waitOps = array_map('intval', is_array($context['waitOps'] ?? null) ? $context['waitOps'] : []);
        $kings = is_array($context['kings'] ?? null) ? $context['kings'] : [HzmjConsts::KING_TILE];
        $kingTile = (int) ($kings[0] ?? HzmjConsts::KING_TILE);
        $ups = is_array($context['ups'] ?? null) ? $context['ups'] : [];
        $canHu = in_array(HzmjConsts::SHOW_OP_WIN, $waitOps, true);

        if ($phase === 'claim') {
            if ($canHu) {
                return new TrustDecision(TrustDecision::ACTION_WIN, ['op' => 'win']);
            }
            if (in_array(HzmjConsts::SHOW_OP_KONG, $waitOps, true)) {
                return new TrustDecision(TrustDecision::ACTION_KONG, ['op' => 'kong']);
            }
            $claimTile = (int) ($context['claimTile'] ?? 0);
            if (in_array(HzmjConsts::SHOW_OP_PONG, $waitOps, true)
                && HzmjRules::shouldAutoPong($hand, $claimTile, $kings)
            ) {
                return new TrustDecision(TrustDecision::ACTION_PONG, ['op' => 'pong']);
            }

            return new TrustDecision(TrustDecision::ACTION_PASS, ['op' => 'pass']);
        }

        // discard / play / draw
        if ($hand === []) {
            return TrustDecision::pass();
        }

        // 摸牌优先：红中变牌穷举/回溯检测自摸
        if ($canHu || (count($hand) % 3 === 2 && self::canSelfDrawWin($hand, $kingTile))) {
            return new TrustDecision(TrustDecision::ACTION_WIN, ['op' => 'win']);
        }
        if (HzmjRules::getOneConcealedKongTile($hand, $kings) > 0
            || HzmjRules::getContinueKongTile($hand, $ups, $kings) > 0
        ) {
            return new TrustDecision(TrustDecision::ACTION_KONG, ['op' => 'kong']);
        }

        $tile = self::suggestDiscard($hand, $context, $kingTile);
        if ($tile <= 0) {
            return TrustDecision::pass();
        }

        return TrustDecision::discard($tile);
    }

    /**
     * 红中变牌自摸检测（回溯拆面子 + 癞子补缺，与规则 canWin 对齐校验）
     *
     * @param list<int> $hand
     */
    public static function canSelfDrawWin(array $hand, int $kingTile = HzmjConsts::KING_TILE): bool
    {
        $hand = array_map('intval', $hand);
        if (count($hand) % 3 !== 2) {
            return false;
        }

        // need 穷举与面子回溯任一成立即可自摸
        return HzmjRules::canWin($hand, [$kingTile]) || self::backtrackWin($hand, $kingTile);
    }

    /**
     * 切牌：永不打红中；按防守优先级选张
     *
     * @param list<int> $hand
     * @param array<string, mixed> $ctx
     */
    public static function suggestDiscard(array $hand, array $ctx = [], int $kingTile = HzmjConsts::KING_TILE): int
    {
        $hand = array_values(array_map('intval', $hand));
        if ($hand === []) {
            return 0;
        }

        $candidates = [];
        foreach ($hand as $tile) {
            if ($tile === $kingTile) {
                continue; // 红中绝对禁止打出
            }
            $candidates[$tile] = $tile;
        }
        if ($candidates === []) {
            // 理论上不应出现「全红中」可切；仍禁止主动打出红中
            return 0;
        }

        $safeSet = self::recentSafeSet(
            is_array($ctx['discards'] ?? null) ? $ctx['discards'] : [],
            self::SAFE_ROUNDS
        );
        $wallLeft = (int) ($ctx['leftTileNum'] ?? $ctx['wallLeft'] ?? 99);
        $endgame = $wallLeft <= self::ENDGAME_WALL || ! empty($ctx['endgame']);

        // 尾局绝对防守：优先跟熟张，允许拆搭子
        if ($endgame) {
            $safePick = self::pickFromSafe($hand, $candidates, $safeSet, true);
            if ($safePick > 0) {
                return $safePick;
            }
        }

        // 1) 字牌孤张（非红中的字牌/边界牌孤张）
        $honorIso = self::pickHonorIsolated($hand, $candidates, $kingTile);
        if ($honorIso > 0) {
            return $honorIso;
        }

        // 2) 近4轮安全熟张（优先孤张熟张）
        $safePick = self::pickFromSafe($hand, $candidates, $safeSet, false);
        if ($safePick > 0) {
            return $safePick;
        }

        // 3) 1/9 边角孤张
        $edgeIso = self::pickEdgeIsolated($hand, $candidates);
        if ($edgeIso > 0) {
            return $edgeIso;
        }

        // 4) 回退：离听更远且更孤的牌（仍绝不打红中）
        $fallback = HzmjRules::suggestDiscard($hand, [$kingTile]);
        if ($fallback === $kingTile || $fallback <= 0) {
            return (int) reset($candidates);
        }

        return $fallback;
    }

    /**
     * @param list<int> $hand
     * @param array<int, int> $candidates
     */
    private static function pickHonorIsolated(array $hand, array $candidates, int $kingTile): int
    {
        foreach ($candidates as $tile) {
            if ($tile === $kingTile) {
                continue;
            }
            // 字牌：不能组顺的牌（>= BOUNDARY），本玩法除红中外预留扩展
            if ($tile < HzmjConsts::BOUNDARY) {
                continue;
            }
            if (HzmjRules::countOf($hand, $tile) === 1) {
                return $tile;
            }
        }

        return 0;
    }

    /**
     * @param list<int> $hand
     * @param array<int, int> $candidates
     * @param array<int, true> $safeSet
     */
    private static function pickFromSafe(array $hand, array $candidates, array $safeSet, bool $allowBreakMeld): int
    {
        if ($safeSet === []) {
            return 0;
        }

        $best = 0;
        $bestScore = PHP_INT_MIN;
        foreach ($candidates as $tile) {
            if (! isset($safeSet[$tile])) {
                continue;
            }
            $iso = self::isolationScore($hand, $tile);
            if (! $allowBreakMeld && $iso <= 0 && HzmjRules::countOf($hand, $tile) > 1) {
                // 非残局：不对搭/对子里的熟张优先拆
                continue;
            }
            // 孤张熟张优先；残局时有熟张即可
            $score = $iso * 10 + ($allowBreakMeld ? 1 : 0);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $tile;
            }
        }

        if ($best > 0) {
            return $best;
        }

        // 残局：任意手中熟张
        if ($allowBreakMeld) {
            foreach ($candidates as $tile) {
                if (isset($safeSet[$tile])) {
                    return $tile;
                }
            }
        }

        return 0;
    }

    /**
     * @param list<int> $hand
     * @param array<int, int> $candidates
     */
    private static function pickEdgeIsolated(array $hand, array $candidates): int
    {
        foreach ($candidates as $tile) {
            if ($tile >= HzmjConsts::BOUNDARY) {
                continue;
            }
            $rank = $tile % 10;
            if ($rank !== 1 && $rank !== 9) {
                continue;
            }
            if (self::isolationScore($hand, $tile) > 0) {
                return $tile;
            }
        }

        return 0;
    }

    /**
     * @param array<string|int, mixed> $discardsBySeat
     * @return array<int, true>
     */
    private static function recentSafeSet(array $discardsBySeat, int $rounds): array
    {
        $safe = [];
        foreach ($discardsBySeat as $list) {
            if (! is_array($list)) {
                continue;
            }
            $tail = array_slice(array_values($list), -$rounds);
            foreach ($tail as $t) {
                $t = (int) $t;
                if ($t > 0 && $t !== HzmjConsts::KING_TILE) {
                    $safe[$t] = true;
                }
            }
        }

        return $safe;
    }

    /**
     * @param list<int> $hand
     */
    private static function isolationScore(array $hand, int $tile): int
    {
        if (HzmjRules::countOf($hand, $tile) !== 1) {
            return 0;
        }
        if ($tile >= HzmjConsts::BOUNDARY) {
            return 3;
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

    /**
     * 回溯：先定将，再拆刻/顺（癞子可补）
     *
     * @param list<int> $hand
     */
    private static function backtrackWin(array $hand, int $kingTile): bool
    {
        $cnt = [];
        $wilds = 0;
        foreach ($hand as $t) {
            $t = (int) $t;
            if ($t === $kingTile) {
                ++$wilds;
            } else {
                $cnt[$t] = ($cnt[$t] ?? 0) + 1;
            }
        }
        ksort($cnt);

        // 将：自然对 / 单+癞 / 双癞
        foreach ($cnt as $tile => $n) {
            if ($n >= 2) {
                $cnt[$tile] -= 2;
                if (self::meldsBacktrack($cnt, $wilds)) {
                    return true;
                }
                $cnt[$tile] += 2;
            } elseif ($n >= 1 && $wilds >= 1) {
                $cnt[$tile] -= 1;
                if (self::meldsBacktrack($cnt, $wilds - 1)) {
                    return true;
                }
                $cnt[$tile] += 1;
            }
        }
        if ($wilds >= 2 && self::meldsBacktrack($cnt, $wilds - 2)) {
            return true;
        }

        return false;
    }

    /**
     * @param array<int, int> $cnt
     */
    private static function meldsBacktrack(array $cnt, int $wilds): bool
    {
        $tile = 0;
        foreach ($cnt as $t => $n) {
            if ($n > 0) {
                $tile = (int) $t;
                break;
            }
        }
        if ($tile === 0) {
            return $wilds === 0 || $wilds % 3 === 0;
        }

        $have = (int) ($cnt[$tile] ?? 0);

        // 刻子：用 0~3 张真牌 + 癞子凑三张
        for ($use = min(3, $have); $use >= 0; --$use) {
            $needWild = 3 - $use;
            if ($needWild > $wilds) {
                continue;
            }
            if ($use === 0 && $needWild === 0) {
                continue;
            }
            $copy = $cnt;
            $copy[$tile] = $have - $use;
            if ($copy[$tile] <= 0) {
                unset($copy[$tile]);
            }
            if (self::meldsBacktrack($copy, $wilds - $needWild)) {
                return true;
            }
        }

        // 顺子（仅数牌）
        if ($tile < HzmjConsts::BOUNDARY) {
            $rank = $tile % 10;
            if ($rank <= 7) {
                $a = $tile;
                $b = $tile + 1;
                $c = $tile + 2;
                $haveA = (int) ($cnt[$a] ?? 0);
                $haveB = (int) ($cnt[$b] ?? 0);
                $haveC = (int) ($cnt[$c] ?? 0);
                $need = (int) ($haveA < 1) + (int) ($haveB < 1) + (int) ($haveC < 1);
                if ($need <= $wilds) {
                    $copy = $cnt;
                    foreach ([[$a, $haveA], [$b, $haveB], [$c, $haveC]] as [$p, $h]) {
                        if ($h > 0) {
                            $copy[$p] = ($copy[$p] ?? 0) - 1;
                            if (($copy[$p] ?? 0) <= 0) {
                                unset($copy[$p]);
                            }
                        }
                    }
                    if (self::meldsBacktrack($copy, $wilds - $need)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}

