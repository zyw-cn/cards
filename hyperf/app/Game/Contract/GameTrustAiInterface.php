<?php

declare(strict_types=1);

namespace App\Game\Contract;

/**
 * 玩法托管 AI：各游戏 Module 实现，供超时/TrustAction 代打调用。
 *
 * 约定 phase（可按玩法扩展）：
 * - bid / grab：叫抢
 * - double：加倍
 * - play：出牌/跟牌
 * - discard / claim：麻将出牌与问询
 * - bet / show / fold：筹码类阶段
 *
 * context 常见键：hand、lastCards、mustLead、mySeat、landlordSeat、lastSeat、
 * seatCounts、waitOps、claimTile、ups、kings、minMultiple 等。
 */
interface GameTrustAiInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function decide(string $phase, array $context = []): TrustDecision;
}
