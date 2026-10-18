<?php

declare(strict_types=1);

namespace App\Game\Dou\Rule;

use App\Game\Dou\DouConsts;

/**
 * 叫地主 / 抢地主 —— 对齐腾讯欢乐斗地主（QQ 棋牌）
 *
 * 协议 score：
 * - 0：不叫 / 不抢
 * - 1：叫地主（尚无人叫）
 * - maxBid+1：抢地主
 *
 * 流程：
 * 1. 系统随机首家，顺时针轮流「叫地主 / 不叫」；有人叫则立刻结束叫牌
 * 2. 三家都不叫 → redraw 重发
 * 3. 叫完后按序抢地主：每人至多一次；不叫过的人不能抢
 * 4. 无人抢 → 叫地主者当地主；有人抢 → 最后抢的人当地主（叫者可反抢一次）
 * 5. 每抢一次倍数 ×2（LandLordBidService::calcMultiple）
 *
 * @see https://qipai.qq.com/ （欢乐斗地主基本玩法）
 */
final class BidRule
{
    /**
     * @param int                                             $first   随机首叫座位
     * @param list<array{seat:int, player_id:int, score:int}> $actions 真实叫抢序列（不含 seq=0）
     * @return array{
     *   next: int|null,
     *   decided: bool,
     *   redraw: bool,
     *   maxBid: int,
     *   landlordSeat: int|null,
     *   stage: string
     * }
     */
    public static function status(int $first, array $actions): array
    {
        $first = (($first % DouConsts::MAX_PLAYERS) + DouConsts::MAX_PLAYERS) % DouConsts::MAX_PLAYERS;
        $maxBid = self::maxBid($actions);

        if ($actions === []) {
            return self::result($first, false, false, 0, null, 'call');
        }

        // ── 叫牌阶段：尚无人叫 ──
        if ($maxBid <= 0) {
            if (count($actions) >= DouConsts::MAX_PLAYERS) {
                return self::result(null, true, true, 0, null, 'call');
            }
            // 严格按「首家起第 n 人」轮转，避免乱序
            $next = ($first + count($actions)) % DouConsts::MAX_PLAYERS;

            return self::result($next, false, false, 0, null, 'call');
        }

        // ── 抢地主阶段 ──
        $callIndex = self::firstPositiveIndex($actions);
        if ($callIndex < 0) {
            return self::result(null, true, true, 0, null, 'call');
        }
        $callSeat = (int) $actions[$callIndex]['seat'];
        $passedCall = self::seatsPassedInCallPhase($actions, $callIndex);

        // 抢牌顺序：叫者下家 → 再下家 →（若已有人抢）叫者反抢
        $robOrder = self::buildRobOrder($callSeat, $actions, $callIndex, $passedCall);

        foreach ($robOrder as $seat) {
            if (! self::hasActedAfter($actions, $seat, $callIndex)) {
                return self::result($seat, false, false, $maxBid, null, 'rob');
            }
        }

        // 所有可抢座位已表态：最后抢者胜，无人抢则叫者胜
        $landlord = self::lastRobSeatAfterCall($actions, $callIndex) ?? $callSeat;

        return self::result(null, true, false, $maxBid, $landlord, 'rob');
    }

    /**
     * @param list<array{seat:int, player_id:int, score:int}> $actions
     */
    public static function isAllowedScore(int $score, array $actions): bool
    {
        $maxBid = self::maxBid($actions);
        if ($score === 0) {
            return true;
        }
        if ($maxBid <= 0) {
            return $score === 1;
        }

        return $score === $maxBid + 1;
    }

    /** @deprecated 兼容旧名 */
    public static function isAllowedGrabScore(int $score): bool
    {
        return $score === 0 || $score === 1 || $score > 1;
    }

    /**
     * @param list<array{seat:int, player_id:int, score:int}> $actions
     * @return array{seat:int, player_id:int, score:int}|null
     */
    public static function pickLandlord(array $actions): ?array
    {
        $status = self::status(0, $actions);
        if (! $status['decided'] || $status['redraw'] || $status['landlordSeat'] === null) {
            return null;
        }
        $seat = (int) $status['landlordSeat'];
        for ($i = count($actions) - 1; $i >= 0; $i--) {
            $a = $actions[$i];
            if ((int) ($a['seat'] ?? -1) === $seat && (int) ($a['score'] ?? 0) > 0) {
                return $a;
            }
        }

        return null;
    }

    /**
     * action：0 不叫/不抢，1 叫地主，2 抢地主
     *
     * @param list<array{seat:int, player_id:int, score:int}> $actionsBefore
     */
    public static function actionOf(int $score, array $actionsBefore): int
    {
        if ($score <= 0) {
            return 0;
        }

        return self::maxBid($actionsBefore) > 0 ? 2 : 1;
    }

    public static function isAllowedDoubleScore(int $score): bool
    {
        return in_array($score, DouConsts::DOUBLE_SCORES, true);
    }

    public static function settleDoubleOf(int $score): int
    {
        // 0=不加倍→1；其余按所选倍数（当前协议仅 2=加倍）
        return $score > 0 ? $score : 1;
    }

    /**
     * 桌面公共倍数 = 叫抢 × 2^炸弹 × 春天
     *
     * 个人加倍不进此值，只存在 farmerDoubles，结算时：
     * 单位分 = 底分 × 本值 × 地主加倍 × 该农民加倍
     */
    public static function tableMultiple(
        int $bidMultiple,
        int $bombCount = 0,
        int $springFactor = 1,
    ): int {
        $bid = max(1, $bidMultiple);
        $bomb = 2 ** max(0, $bombCount);
        $spring = max(1, $springFactor);

        return max(1, $bid * $bomb * $spring);
    }

    /**
     * 加倍是否已全部完成（三家都已选择）
     *
     * @param array<string, int> $farmerDoubles
     */
    public static function isDoubleComplete(
        array $farmerDoubles,
        int $maxPlayers = DouConsts::MAX_PLAYERS,
    ): bool {
        for ($seat = 0; $seat < $maxPlayers; $seat++) {
            if (! isset($farmerDoubles[(string) $seat])) {
                return false;
            }
        }

        return true;
    }

    /**
     * 尚未选择加倍的座位列表（座位号升序）
     *
     * @param array<string, int> $farmerDoubles
     * @return list<int>
     */
    public static function pendingDoubleSeats(
        array $farmerDoubles,
        int $maxPlayers = DouConsts::MAX_PLAYERS,
    ): array {
        $pending = [];
        for ($seat = 0; $seat < $maxPlayers; $seat++) {
            if (! isset($farmerDoubles[(string) $seat])) {
                $pending[] = $seat;
            }
        }

        return $pending;
    }

    /**
     * 加倍阶段剩余秒数（按 doubleDeadlineAt；无截止时间则返回默认超时）
     *
     * @param array<string, mixed> $state
     */
    public static function doubleRemainSeconds(
        array $state,
        int $defaultTimeout = DouConsts::DOUBLE_TIMEOUT,
    ): int {
        $deadline = (int) ($state['doubleDeadlineAt'] ?? 0);
        if ($deadline <= 0) {
            return max(0, $defaultTimeout);
        }

        return max(0, $deadline - time());
    }

    /**
     * @deprecated 已改为三家同时加倍；保留供旧逻辑/兼容调用
     * 加倍轮次：从地主起按座位顺序找下一个未选择的座位；全选完返回 null
     *
     * @param array<string, int> $farmerDoubles
     */
    public static function nextDoubleTurn(
        int $landlordSeat,
        array $farmerDoubles,
        int $maxPlayers = DouConsts::MAX_PLAYERS,
    ): ?int {
        $pending = self::pendingDoubleSeats($farmerDoubles, $maxPlayers);
        if ($pending === []) {
            return null;
        }
        $start = max(0, $landlordSeat);
        for ($i = 0; $i < $maxPlayers; $i++) {
            $seat = ($start + $i) % $maxPlayers;
            if (in_array($seat, $pending, true)) {
                return $seat;
            }
        }

        return $pending[0];
    }

    /**
     * @param list<array{seat:int, player_id:int, score:int}> $actions
     */
    public static function maxBid(array $actions): int
    {
        $max = 0;
        foreach ($actions as $a) {
            $s = (int) ($a['score'] ?? 0);
            if ($s > $max) {
                $max = $s;
            }
        }

        return $max;
    }

    /**
     * @return array{
     *   next: int|null,
     *   decided: bool,
     *   redraw: bool,
     *   maxBid: int,
     *   landlordSeat: int|null,
     *   stage: string
     * }
     */
    private static function result(
        ?int $next,
        bool $decided,
        bool $redraw,
        int $maxBid,
        ?int $landlordSeat,
        string $stage,
    ): array {
        return [
            'next'         => $next,
            'decided'      => $decided,
            'redraw'       => $redraw,
            'maxBid'       => $maxBid,
            'landlordSeat' => $landlordSeat,
            'stage'        => $stage,
        ];
    }

    /**
     * @param list<array{seat:int, player_id:int, score:int}> $actions
     */
    private static function firstPositiveIndex(array $actions): int
    {
        foreach ($actions as $i => $a) {
            if ((int) ($a['score'] ?? 0) > 0) {
                return (int) $i;
            }
        }

        return -1;
    }

    /**
     * 叫牌阶段说过「不叫」的座位（官方：不能再抢地主）
     *
     * @param list<array{seat:int, player_id:int, score:int}> $actions
     * @return array<int, true> seat => true
     */
    private static function seatsPassedInCallPhase(array $actions, int $callIndex): array
    {
        $passed = [];
        for ($i = 0; $i < $callIndex; $i++) {
            if ((int) ($actions[$i]['score'] ?? 0) === 0) {
                $passed[(int) $actions[$i]['seat']] = true;
            }
        }

        return $passed;
    }

    /**
     * 抢地主询问顺序（每人至多一次）：
     * 叫者下家 → 再下家 → 若已有人抢则叫者反抢
     *
     * @param list<array{seat:int, player_id:int, score:int}> $actions
     * @param array<int, true>                                $passedCall
     * @return list<int>
     */
    private static function buildRobOrder(
        int $callSeat,
        array $actions,
        int $callIndex,
        array $passedCall,
    ): array {
        $order = [];
        for ($i = 1; $i < DouConsts::MAX_PLAYERS; $i++) {
            $seat = ($callSeat + $i) % DouConsts::MAX_PLAYERS;
            if (isset($passedCall[$seat])) {
                continue; // 不叫过的人不能抢
            }
            $order[] = $seat;
        }

        // 已有其他人抢过，才给叫者反抢机会
        if (self::lastRobSeatAfterCall($actions, $callIndex) !== null) {
            $order[] = $callSeat;
        }

        return $order;
    }

    /**
     * 叫牌之后最近一次「抢」（score>0 且不是叫牌那一步）的座位
     *
     * @param list<array{seat:int, player_id:int, score:int}> $actions
     */
    private static function lastRobSeatAfterCall(array $actions, int $callIndex): ?int
    {
        $seat = null;
        for ($i = $callIndex + 1; $i < count($actions); $i++) {
            if ((int) ($actions[$i]['score'] ?? 0) > 0) {
                $seat = (int) $actions[$i]['seat'];
            }
        }

        return $seat;
    }

    /**
     * @param list<array{seat:int, player_id:int, score:int}> $actions
     */
    private static function hasActedAfter(array $actions, int $seat, int $afterIndex): bool
    {
        for ($i = $afterIndex + 1; $i < count($actions); $i++) {
            if ((int) ($actions[$i]['seat'] ?? -1) === $seat) {
                return true;
            }
        }

        return false;
    }
}
