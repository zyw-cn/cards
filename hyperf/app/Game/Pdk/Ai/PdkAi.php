<?php

declare(strict_types=1);

namespace App\Game\Pdk\Ai;

use App\Game\Contract\GameTrustAiInterface;
use App\Game\Contract\TrustDecision;
use App\Game\Pdk\Card\PdkCard;
use App\Game\Pdk\PdkConsts;

/**
 * 跑得快托管 AI
 *
 * 1. 有牌必管：能压必须压，禁止在可管时 PASS
 * 2. 管牌最小代价：同型最小能管，其次才炸弹 / 三个 A
 * 3. 自由出牌：优先最长顺子 / 连对
 * 4. 防放走：下家剩 1 张时，出单必须用手中最大单张
 */
final class PdkAi implements GameTrustAiInterface
{
    public const ACTION_PASS = 'PASS';

    public const ACTION_PLAY = 'PLAY';

    public function decide(string $phase, array $context = []): TrustDecision
    {
        $phase = strtolower(trim($phase));
        if (in_array($phase, ['double'], true)) {
            return TrustDecision::passCall(0);
        }

        $hand = is_array($context['hand'] ?? null) ? $context['hand'] : [];
        $lastCards = is_array($context['lastCards'] ?? null) ? $context['lastCards'] : [];
        $play = self::decidePlay($hand, $lastCards, $context);
        if (($play['action'] ?? '') === self::ACTION_PLAY) {
            return TrustDecision::play((array) ($play['cards'] ?? []));
        }

        return TrustDecision::pass();
    }

    /**
     * @param list<string> $hand
     * @param list<string> $lastCards
     * @param array{
     *   mustLead?: bool,
     *   mySeat?: int,
     *   nextSeatCount?: int,
     *   seatCounts?: array<int,int>,
     *   maxPlayers?: int,
     *   forceLargestSingle?: bool,
     *   opponentOneCard?: bool,
     *   needSpade3?: bool
     * } $ctx
     * @return array{action:string,cards:list<string>}
     */
    public static function decidePlay(array $hand, array $lastCards = [], array $ctx = []): array
    {
        $hand = array_values(array_filter(array_map(
            static fn ($c) => strtoupper(trim((string) $c)),
            $hand
        ), static fn (string $c): bool => $c !== ''));
        if ($hand === []) {
            return ['action' => self::ACTION_PASS, 'cards' => []];
        }

        $mustLead = ! empty($ctx['mustLead']) || $lastCards === [];
        $nextOne = self::nextHasOneCard($ctx);

        if ($mustLead) {
            $cards = PdkCard::suggestPlay(
                $hand,
                [],
                ! empty($ctx['needSpade3']),
                $nextOne,
                false
            );
            // 首出不能不出
            if ($cards === []) {
                $sorted = $hand;
                PdkCard::sortCards($sorted);
                $cards = [$nextOne ? $sorted[count($sorted) - 1] : $sorted[0]];
            }
            $cards = self::applyFangzouGuard($hand, $cards, $nextOne);

            return ['action' => self::ACTION_PLAY, 'cards' => $cards];
        }

        // —— 有牌必管硬性过滤 ——
        if (! PdkCard::canBeat($hand, $lastCards)) {
            return ['action' => self::ACTION_PASS, 'cards' => []];
        }

        [$lastType] = PdkCard::analyzeCards($lastCards);
        // 跟单且下家报单：必须最大单（防放走）；其它牌型走最小代价
        $forceLargest = $nextOne && $lastType === PdkCard::CARD_TYPE_SINGLE;

        $cards = PdkCard::suggestPlay(
            $hand,
            $lastCards,
            ! empty($ctx['needSpade3']),
            $forceLargest,
            false
        );

        // 可管却未给出牌：兜底再算一次（仍最小代价 / 必要炸）
        if ($cards === []) {
            $cards = PdkCard::suggestPlay($hand, $lastCards, false, false, false);
        }

        if ($cards === []) {
            // 理论上 canBeat=true 不应到此；保守 PASS 避免非法出牌
            return ['action' => self::ACTION_PASS, 'cards' => []];
        }

        $cards = self::applyFangzouGuard($hand, $cards, $nextOne);

        return ['action' => self::ACTION_PLAY, 'cards' => $cards];
    }

    /**
     * 下家剩 1 张且本手是单张 → 强制换成手中最大单
     *
     * @param list<string> $hand
     * @param list<string> $cards
     * @return list<string>
     */
    private static function applyFangzouGuard(array $hand, array $cards, bool $nextOne): array
    {
        if (! $nextOne || count($cards) !== 1) {
            return $cards;
        }
        [$type] = PdkCard::analyzeCards($cards);
        if ($type !== PdkCard::CARD_TYPE_SINGLE) {
            return $cards;
        }

        $sorted = $hand;
        PdkCard::sortCards($sorted);
        $largest = $sorted[count($sorted) - 1];

        return [$largest];
    }

    /**
     * @param array<string, mixed> $ctx
     */
    private static function nextHasOneCard(array $ctx): bool
    {
        if (! empty($ctx['forceLargestSingle']) || ! empty($ctx['opponentOneCard'])) {
            return true;
        }
        if (array_key_exists('nextSeatCount', $ctx)) {
            return (int) $ctx['nextSeatCount'] === 1;
        }

        $mySeat = (int) ($ctx['mySeat'] ?? -1);
        $counts = is_array($ctx['seatCounts'] ?? null) ? $ctx['seatCounts'] : [];
        if ($mySeat < 0 || $counts === []) {
            return false;
        }
        $n = max(2, (int) ($ctx['maxPlayers'] ?? PdkConsts::MAX_PLAYERS));
        $next = ($mySeat + 1) % $n;

        return (int) ($counts[$next] ?? 99) === 1;
    }
}
