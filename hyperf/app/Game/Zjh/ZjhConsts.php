<?php

declare(strict_types=1);

namespace App\Game\Zjh;

/**
 * 炸金花 Module 内共享常量
 *
 * 阶段：ready（准备中，round.status=PENDING）→ bet（跟注轮）→ settle（已结算，round 结束）
 * 局内桌面状态写入 game_round.extra，见 {@see RoundState}
 *
 * 下注金额：
 * - select_points：闷/看加注可选绝对金额档
 * - max_follow：看牌最高可出金额；闷牌最高 = select_points 中 ≤ max_follow×0.5 的最大值
 * - unit：当前闷注；闷跟=unit
 * - 看跟=select_points 中 ≥ unit×2 的最小档（避免 2→4、4→8 落在非档位上）
 *   例：闷1→看2，闷2→看5，闷4→看10
 */
final class ZjhConsts
{
    /** 下注/操作轮：看牌、跟注、加注、弃牌、比牌 */
    public const PHASE_BET = 'bet';

    /** 本局已结算（仅推送用；落库后 round.status=ENDED） */
    public const PHASE_SETTLE = 'settle';

    /** 人数与斗牛一致 2~10 */
    public const MAX_PLAYERS = 10;

    public const MIN_PLAYERS = 2;

    public const CARDS_PER_PLAYER = 3;

    /**
     * 闷/看加注可选绝对金额（金币）
     * @var list<string>
     */
    public const SELECT_POINTS = ['0.50', '1.00', '2.00', '5.00', '10.00', '20.00', '50.00'];

    /** 创房「最大跟注」默认（看牌封顶，绝对金额） */
    public const DEFAULT_MAX_FOLLOW = '5.00';

    /** 轮到操作超时（秒）；超时自动弃牌 */
    public const TURN_TIMEOUT = 20;

    /**
     * 规范化金额为 2 位小数
     */
    public static function normAmount(string|int|float $amount): string
    {
        return bcadd((string) $amount, '0', 2);
    }

    /**
     * 是否在 select_points 档位内
     */
    public static function isSelectPoint(string $amount): bool
    {
        $amount = self::normAmount($amount);
        foreach (self::SELECT_POINTS as $p) {
            if (bccomp($amount, $p, 2) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * 看牌应付额：select_points 中 ≥ unit×2 的最小档
     * 例：unit=1→2；unit=2→5；unit=4→10；unit=5→10
     *
     * @param list<string|int|float>|null $selectPoints
     */
    public static function lookCallFromUnit(string|int|float $unit, ?array $selectPoints = null): string
    {
        $points = [];
        $src = $selectPoints ?? self::SELECT_POINTS;
        foreach ($src as $p) {
            if (is_array($p)) {
                foreach ($p as $k => $_) {
                    $points[] = self::normAmount($k);
                }
            } else {
                $points[] = self::normAmount($p);
            }
        }
        if ($points === []) {
            $points = self::SELECT_POINTS;
        }
        $points = array_values(array_unique($points));
        usort($points, static fn (string $a, string $b): int => bccomp($a, $b, 2));

        $raw = bcmul(self::normAmount($unit), '2', 2);
        foreach ($points as $p) {
            if (bccomp($p, $raw, 2) >= 0) {
                return $p;
            }
        }

        return $raw;
    }

    /**
     * 看加支付额对应的闷注：取 lookCall(p)=lookAmount 的最大档位，否则 lookAmount/2
     *
     * @param list<string|int|float>|null $selectPoints
     */
    public static function menUnitFromLookAmount(string|int|float $lookAmount, ?array $selectPoints = null): string
    {
        $lookAmount = self::normAmount($lookAmount);
        $points = [];
        $src = $selectPoints ?? self::SELECT_POINTS;
        foreach ($src as $p) {
            if (is_array($p)) {
                foreach ($p as $k => $_) {
                    $points[] = self::normAmount($k);
                }
            } else {
                $points[] = self::normAmount($p);
            }
        }
        if ($points === []) {
            $points = self::SELECT_POINTS;
        }
        $points = array_values(array_unique($points));
        usort($points, static fn (string $a, string $b): int => bccomp($a, $b, 2));

        $best = null;
        foreach ($points as $p) {
            if (bccomp(self::lookCallFromUnit($p, $points), $lookAmount, 2) === 0) {
                $best = $p;
            }
        }

        return $best ?? bcdiv($lookAmount, '2', 2);
    }

    /**
     * 闷牌封顶：select_points 中 ≤ maxFollow×0.5 的最大档
     * 例：maxFollow=5 → 2；maxFollow=10 → 5
     */
    public static function menMaxFromFollow(string|int|float $maxFollow): string
    {
        $half = bcmul(self::normAmount($maxFollow), '0.5', 2);
        $best = '0.00';
        foreach (self::SELECT_POINTS as $p) {
            if (bccomp($p, $half, 2) <= 0 && bccomp($p, $best, 2) > 0) {
                $best = $p;
            }
        }

        return $best;
    }

    /**
     * 从房间 rule JSON 解析看牌封顶 / 闷牌封顶
     *
     * @return array{look_max:string, men_max:string, select_points:list<string>}
     */
    public static function capsFromRule(?string $rule): array
    {
        $data = [];
        $raw = trim((string) $rule);
        if ($raw !== '' && ($raw[0] ?? '') === '{') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        $lookMax = self::normAmount(
            $data['look_max'] ?? $data['max_follow'] ?? $data['maxFollow'] ?? self::DEFAULT_MAX_FOLLOW
        );
        if (bccomp($lookMax, '0', 2) <= 0) {
            $lookMax = self::DEFAULT_MAX_FOLLOW;
        }

        $menMax = isset($data['men_max'])
            ? self::normAmount($data['men_max'])
            : self::menMaxFromFollow($lookMax);
        if (bccomp($menMax, '0', 2) <= 0) {
            $menMax = self::menMaxFromFollow($lookMax);
        }
        // 闷封顶不得超过看封顶的一半档
        $halfCap = self::menMaxFromFollow($lookMax);
        if (bccomp($menMax, $halfCap, 2) > 0) {
            $menMax = $halfCap;
        }

        $points = [];
        if (! empty($data['select_points']) && is_array($data['select_points'])) {
            foreach ($data['select_points'] as $p) {
                if (is_array($p)) {
                    foreach ($p as $k => $_) {
                        $points[] = self::normAmount($k);
                    }
                } else {
                    $points[] = self::normAmount($p);
                }
            }
        }
        if ($points === []) {
            $points = self::SELECT_POINTS;
        }

        return [
            'look_max'      => $lookMax,
            'men_max'       => $menMax,
            'select_points' => array_values(array_unique($points)),
        ];
    }
}
