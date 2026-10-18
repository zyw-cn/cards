<?php

declare(strict_types=1);

namespace App\Game\Niu\Ai;

use App\Game\Contract\GameTrustAiInterface;
use App\Game\Contract\TrustDecision;
use App\Game\Niu\Card\NiuCard;
use App\Game\Niu\NiuConsts;

/**
 * 斗牛托管 — 极简「免决策」规则
 *
 * 1. 抢庄：托管无条件不抢（multiple=0）
 * 2. 下注：闲家固定最低倍数（资产保护）
 * 3. 摊牌：纯程序算牛，自动组合最大牛型并亮牌（无心理博弈）
 */
final class NiuTrustAi implements GameTrustAiInterface
{
    public function decide(string $phase, array $context = []): TrustDecision
    {
        return match (strtolower(trim($phase))) {
            'grab', NiuConsts::PHASE_GRAB => self::decideGrab($context),
            'bet', NiuConsts::PHASE_BET   => self::decideBet($context),
            'show', 'reveal', NiuConsts::PHASE_SHOW => self::decideShow($context),
            default                       => TrustDecision::pass(),
        };
    }

    /**
     * 抢庄：不抢庄（0）。若业务强制最低 1 倍，可传 context.forceMinGrab=1。
     *
     * @param array<string, mixed> $ctx
     */
    public static function decideGrab(array $ctx = []): TrustDecision
    {
        $multiple = ! empty($ctx['forceMinGrab']) ? 1 : 0;
        if (! in_array($multiple, NiuConsts::GRAB_MULTIPLES, true)) {
            $multiple = 0;
        }

        return new TrustDecision(TrustDecision::ACTION_GRAB, [
            'multiple' => $multiple,
            'op'       => 'grab',
            'reason'   => 'trust_no_decision',
        ]);
    }

    /**
     * 闲家下注：最低倍数（默认 BET_MULTIPLES[0]=1）
     *
     * @param array<string, mixed> $ctx
     */
    public static function decideBet(array $ctx = []): TrustDecision
    {
        $min = (int) ($ctx['minMultiple'] ?? (NiuConsts::BET_MULTIPLES[0] ?? 1));
        if (! in_array($min, NiuConsts::BET_MULTIPLES, true)) {
            $min = NiuConsts::BET_MULTIPLES[0] ?? 1;
        }

        return new TrustDecision(TrustDecision::ACTION_BET, [
            'multiple' => $min,
            'op'       => 'bet',
            'reason'   => 'asset_protect',
        ]);
    }

    /**
     * 摊牌：后端算牛并拆出最大牛型组合，直接亮牌
     *
     * @param array{hand?: list<string>, multipliers?: array<string,int>} $ctx
     */
    public static function decideShow(array $ctx = []): TrustDecision
    {
        $hand = is_array($ctx['hand'] ?? null) ? array_values($ctx['hand']) : [];
        $multipliers = is_array($ctx['multipliers'] ?? null) ? $ctx['multipliers'] : [];
        $ev = $hand === [] || count($hand) !== 5
            ? ['type' => 0, 'label' => '无牛', 'multiple' => 1, 'group3' => [], 'rest2' => []]
            : NiuCard::evaluate($hand, $multipliers);

        // 特殊牌型无 3+2 拆分时，仍补一份 bestNiuSplit 供前端展示
        $split = count($hand) === 5 ? NiuCard::bestNiuSplit($hand) : ['group3' => [], 'rest2' => []];
        $group3 = $ev['group3'] ?? $split['group3'] ?? [];
        $rest2 = $ev['rest2'] ?? $split['rest2'] ?? [];

        return new TrustDecision(TrustDecision::ACTION_SHOW, [
            'op'          => 'show',
            'niuType'     => (int) ($ev['type'] ?? 0),
            'niuLabel'    => (string) ($ev['label'] ?? '无牛'),
            'niuMultiple' => (int) ($ev['multiple'] ?? 1),
            'group3'      => array_values(is_array($group3) ? $group3 : []),
            'rest2'       => array_values(is_array($rest2) ? $rest2 : []),
            'reason'      => 'auto_max_niu',
        ]);
    }
}
