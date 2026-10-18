<?php

declare(strict_types=1);

namespace App\Game\Zjh\Ai;

use App\Game\Contract\GameTrustAiInterface;
use App\Game\Contract\TrustDecision;
use App\Game\Zjh\Card\ZjhCard;
use App\Game\Zjh\RoundState;
use App\Game\Zjh\ZjhConsts;

/**
 * 炸金花托管 AI
 *
 * 1. 前 2 轮无条件闷跟
 * 2. 看牌后分垃圾 / 中等 / 大牌
 * 3. 垃圾：10% 大额诈唬，否则弃牌
 * 4. 中等：只平跟
 * 5. 大牌：前期微调加注钓鱼，后期疯狂收割
 * 6. 单局下注 > 总资产 40% → 强制比牌止损
 */
final class ZajinhuaAi implements GameTrustAiInterface
{
    public const GRADE_GARBAGE = 'garbage';

    public const GRADE_MEDIUM = 'medium';

    public const GRADE_BIG = 'big';

    /** 前 N 轮闷跟 */
    private const BLIND_ROUNDS = 2;

    /** 大牌「后期」起始轮次 */
    private const LATE_ROUND = 5;

    /** 垃圾牌诈唬概率（百分比） */
    private const BLUFF_PCT = 10;

    /** 单局投入占（余额+已投入）超过此比例则比牌止损 */
    private const STOP_LOSS_RATIO = '0.40';

    public function decide(string $phase, array $context = []): TrustDecision
    {
        $phase = strtolower(trim($phase));
        if (! in_array($phase, ['bet', 'turn', 'play', ''], true) && $phase !== ZjhConsts::PHASE_BET) {
            return TrustDecision::pass();
        }

        return self::decideTurn($context);
    }

    /**
     * @param array{
     *   hand?: list<string>,
     *   state?: array<string, mixed>,
     *   mySeat?: int,
     *   looked?: bool,
     *   betRound?: int,
     *   balance?: string|int|float,
     *   invested?: string|int|float,
     *   caps?: array{look_max?:string,men_max?:string,select_points?:list<string>},
     *   select_points?: list<string>
     * } $ctx
     */
    public static function decideTurn(array $ctx): TrustDecision
    {
        $state = is_array($ctx['state'] ?? null) ? $ctx['state'] : [];
        $mySeat = (int) ($ctx['mySeat'] ?? $state['turnSeat'] ?? -1);
        $looked = array_key_exists('looked', $ctx)
            ? ! empty($ctx['looked'])
            : ($mySeat >= 0 && RoundState::isLooked($state, $mySeat));
        $betRound = (int) ($ctx['betRound'] ?? 0);
        if ($betRound <= 0) {
            $betRound = self::estimateBetRound($state);
        }

        $caps = is_array($ctx['caps'] ?? null) ? $ctx['caps'] : [];
        if ($caps === []) {
            $caps = [
                'look_max'      => ZjhConsts::DEFAULT_MAX_FOLLOW,
                'men_max'       => ZjhConsts::menMaxFromFollow(ZjhConsts::DEFAULT_MAX_FOLLOW),
                'select_points' => ZjhConsts::SELECT_POINTS,
            ];
        }
        $points = is_array($caps['select_points'] ?? null)
            ? $caps['select_points']
            : (is_array($ctx['select_points'] ?? null) ? $ctx['select_points'] : ZjhConsts::SELECT_POINTS);

        $invested = ZjhConsts::normAmount((string) ($ctx['invested']
            ?? ($state['invested'][(string) $mySeat] ?? '0')));
        $balance = ZjhConsts::normAmount((string) ($ctx['balance'] ?? '0'));

        // 止损：单局已下注超过总资产 40% → 强制比牌
        if (self::needStopLossCompare($invested, $balance)) {
            $target = self::pickCompareTarget($state, $mySeat);
            if ($target >= 0) {
                return new TrustDecision(TrustDecision::ACTION_COMPARE, [
                    'op'         => 'compare',
                    'targetSeat' => $target,
                    'reason'     => 'stop_loss',
                ]);
            }
        }

        // 前 2 轮：无条件闷跟（未看牌）
        if ($betRound <= self::BLIND_ROUNDS) {
            if ($looked) {
                // 异常已看：仍平跟
                return new TrustDecision(TrustDecision::ACTION_CALL, ['op' => 'call', 'men' => false]);
            }

            return new TrustDecision(TrustDecision::ACTION_CALL, [
                'op'  => 'call',
                'men' => true,
                'reason' => 'blind_men',
            ]);
        }

        // 第 3 轮起：先看牌（若尚未看）
        if (! $looked) {
            return new TrustDecision(TrustDecision::ACTION_LOOK, ['op' => 'look']);
        }

        $hand = is_array($ctx['hand'] ?? null) ? array_values($ctx['hand']) : [];
        $grade = self::gradeHand($hand);

        return match ($grade) {
            self::GRADE_GARBAGE => self::decideGarbage($state, $mySeat, $looked, $caps, $points),
            self::GRADE_MEDIUM  => new TrustDecision(TrustDecision::ACTION_CALL, [
                'op'    => 'call',
                'grade' => $grade,
            ]),
            default             => self::decideBig($state, $mySeat, $looked, $betRound, $caps, $points),
        };
    }

    /**
     * 手牌分级：垃圾 / 中等 / 大牌
     *
     * @param list<string> $cards
     */
    public static function gradeHand(array $cards): string
    {
        $ev = ZjhCard::evaluate($cards);
        $type = (int) ($ev['type'] ?? ZjhCard::TYPE_HIGH);

        if ($type >= ZjhCard::TYPE_FLUSH) {
            return self::GRADE_BIG; // 金花 / 顺子 / 同花顺 / 豹子
        }
        if ($type === ZjhCard::TYPE_STRAIGHT) {
            return self::GRADE_BIG;
        }
        if ($type === ZjhCard::TYPE_PAIR) {
            $pairRank = ZjhCard::rankPoint((int) ($ev['pairPoint'] ?? 0));

            // 对 J 及以上算大牌，小对中等
            return $pairRank >= 11 ? self::GRADE_BIG : self::GRADE_MEDIUM;
        }

        // 散牌：最大 ≥Q 中等，否则垃圾
        $points = is_array($ev['points'] ?? null) ? $ev['points'] : [];
        $maxRank = 0;
        foreach ($points as $p) {
            $maxRank = max($maxRank, ZjhCard::rankPoint((int) $p));
        }

        return $maxRank >= 12 ? self::GRADE_MEDIUM : self::GRADE_GARBAGE;
    }

    /**
     * @param array<string, mixed> $state
     */
    public static function estimateBetRound(array $state): int
    {
        $alive = RoundState::aliveSeats($state);
        $n = max(1, count($alive));
        $unit = (float) ZjhConsts::normAmount((string) ($state['unit'] ?? '0'));
        if ($unit <= 0) {
            $unit = (float) ZjhConsts::normAmount((string) ($state['ante'] ?? '1'));
        }
        if ($unit <= 0) {
            $unit = 1.0;
        }

        $total = 0.0;
        foreach ($state['invested'] ?? [] as $v) {
            $total += (float) $v;
        }
        $ante = (float) ZjhConsts::normAmount((string) ($state['ante'] ?? '0'));
        $players = max(1, count($state['seatPids'] ?? []));
        $net = max(0.0, $total - $ante * $players);

        return max(1, (int) floor($net / ($n * $unit)) + 1);
    }

    public static function needStopLossCompare(string $invested, string $balance): bool
    {
        $invested = ZjhConsts::normAmount($invested);
        $balance = ZjhConsts::normAmount($balance);
        $total = bcadd($invested, $balance, 2);
        if (bccomp($total, '0', 2) <= 0) {
            return false;
        }
        $ratio = bcdiv($invested, $total, 4);

        return bccomp($ratio, self::STOP_LOSS_RATIO, 4) > 0;
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $caps
     * @param list<string> $points
     */
    private static function decideGarbage(
        array $state,
        int $mySeat,
        bool $looked,
        array $caps,
        array $points,
    ): TrustDecision {
        if (random_int(1, 100) <= self::BLUFF_PCT) {
            $amount = self::pickRaiseAmount($state, $looked, $caps, $points, true);
            if ($amount !== '') {
                return new TrustDecision(TrustDecision::ACTION_RAISE, [
                    'op'     => 'raise',
                    'amount' => $amount,
                    'grade'  => self::GRADE_GARBAGE,
                    'reason' => 'bluff',
                ]);
            }
        }

        return new TrustDecision(TrustDecision::ACTION_FOLD, [
            'op'    => 'fold',
            'grade' => self::GRADE_GARBAGE,
        ]);
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $caps
     * @param list<string> $points
     */
    private static function decideBig(
        array $state,
        int $mySeat,
        bool $looked,
        int $betRound,
        array $caps,
        array $points,
    ): TrustDecision {
        unset($mySeat);
        $late = $betRound >= self::LATE_ROUND;
        $amount = self::pickRaiseAmount($state, $looked, $caps, $points, $late);
        if ($amount !== '') {
            return new TrustDecision(TrustDecision::ACTION_RAISE, [
                'op'     => 'raise',
                'amount' => $amount,
                'grade'  => self::GRADE_BIG,
                'reason' => $late ? 'harvest' : 'fish',
            ]);
        }

        // 无法加注时平跟
        return new TrustDecision(TrustDecision::ACTION_CALL, [
            'op'    => 'call',
            'grade' => self::GRADE_BIG,
        ]);
    }

    /**
     * 选加注额：微调=刚大于当前跟注的最小档；疯狂=封顶最大档
     *
     * @param array<string, mixed> $state
     * @param array<string, mixed> $caps
     * @param list<string> $points
     */
    private static function pickRaiseAmount(
        array $state,
        bool $looked,
        array $caps,
        array $points,
        bool $aggressive,
    ): string {
        $points = array_values(array_unique(array_map(
            static fn ($p) => ZjhConsts::normAmount((string) $p),
            $points
        )));
        usort($points, static fn (string $a, string $b): int => bccomp($a, $b, 2));

        $curUnit = ZjhConsts::normAmount((string) ($state['unit'] ?? '0'));
        $curCall = $looked
            ? ZjhConsts::lookCallFromUnit($curUnit, $points)
            : $curUnit;
        $max = $looked
            ? ZjhConsts::normAmount((string) ($caps['look_max'] ?? ZjhConsts::DEFAULT_MAX_FOLLOW))
            : ZjhConsts::normAmount((string) ($caps['men_max'] ?? '0'));

        $cands = [];
        foreach ($points as $p) {
            if (bccomp($p, $curCall, 2) <= 0) {
                continue;
            }
            if (bccomp($p, $max, 2) > 0) {
                continue;
            }
            // 闷加：amount 即新 unit；看加：amount 为支付档
            if (! $looked && bccomp($p, $curUnit, 2) <= 0) {
                continue;
            }
            $cands[] = $p;
        }
        if ($cands === []) {
            return '';
        }

        return $aggressive ? $cands[count($cands) - 1] : $cands[0];
    }

    /**
     * @param array<string, mixed> $state
     */
    private static function pickCompareTarget(array $state, int $mySeat): int
    {
        $alive = RoundState::aliveSeats($state);
        // 优先上一家存活对手
        $ordered = array_values(array_map('intval', array_keys($state['seatPids'] ?? [])));
        sort($ordered);
        $n = count($ordered);
        $idx = array_search($mySeat, $ordered, true);
        if ($idx !== false && $n > 0) {
            for ($i = 1; $i < $n; $i++) {
                $cand = $ordered[($idx - $i + $n) % $n];
                if ($cand !== $mySeat && in_array($cand, $alive, true)) {
                    return $cand;
                }
            }
        }
        foreach ($alive as $s) {
            if ($s !== $mySeat) {
                return $s;
            }
        }

        return -1;
    }
}
