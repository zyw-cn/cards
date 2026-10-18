<?php

declare(strict_types=1);

namespace App\Game\Lz\Ai;

use App\Game\Contract\GameTrustAiInterface;
use App\Game\Contract\TrustDecision;
use App\Game\Lz\Card\LaiziCard;
use App\Game\Lz\LzConsts;
use App\Game\Support\LandlordSeatRole;

/**
 * 癞子斗地主 AI 托管
 *
 * 1. 叫抢：托管一律 PASS_CALL（不叫/不抢）
 * 2. 管牌：癞子变牌穷举，代价 硬牌 → 软牌 → 软炸 → 硬炸 → 王炸
 * 3. 农民下家（门板）：顶牌；上家（守门员）：喂牌、不卡队友
 * 4. 地主：能管就管；首出优先组合，炸弹/王炸尽量留到末手
 */
final class LaiziLandlordAi implements GameTrustAiInterface
{
    public const ACTION_PASS_CALL = 'PASS_CALL';

    public const ACTION_PASS = 'PASS';

    public const ACTION_PLAY = 'PLAY';

    public const ROLE_NEXT = LandlordSeatRole::NEXT;

    public const ROLE_PREV = LandlordSeatRole::PREV;

    public const ROLE_LANDLORD = LandlordSeatRole::LANDLORD;

    private const WEIGHT_J = 11;

    private const WEIGHT_A = 14;

    private const WEIGHT_2 = 15;

    private const WEIGHT_SJOKER = 16;

    private const WEIGHT_BJOKER = 17;

    private const SMALL_SINGLE_MAX = 10;

    private const SMALL_PAIR_MAX = 9;

    private const PARTNER_BIG_WEIGHT = 11;

    public function decide(string $phase, array $context = []): TrustDecision
    {
        $phase = strtolower(trim($phase));
        if (in_array($phase, ['bid', 'grab', 'call', 'double'], true)) {
            $bid = self::decideBid();

            return TrustDecision::passCall((int) ($bid['score'] ?? 0));
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
     * @return array{action:string,score:int}
     */
    public static function decideBid(): array
    {
        return [
            'action' => self::ACTION_PASS_CALL,
            'score'  => 0,
        ];
    }

    public static function farmerRole(int $mySeat, int $landlordSeat, int $maxPlayers = LzConsts::MAX_PLAYERS): string
    {
        return LandlordSeatRole::of($mySeat, $landlordSeat, $maxPlayers);
    }

    /**
     * @param list<string> $hand
     * @param list<string> $lastCards
     * @param array{
     *   mustLead?: bool,
     *   mySeat?: int,
     *   landlordSeat?: int,
     *   lastSeat?: int,
     *   seatCounts?: array<int,int>,
     *   maxPlayers?: int,
     *   threat?: bool,
     *   partnerLead?: bool
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

        $finish = LaiziCard::suggestFinishPlay($hand, $lastCards);
        if ($finish !== []) {
            return ['action' => self::ACTION_PLAY, 'cards' => $finish];
        }

        $mustLead = ! empty($ctx['mustLead']) || $lastCards === [];
        $mySeat = (int) ($ctx['mySeat'] ?? -1);
        $landlordSeat = (int) ($ctx['landlordSeat'] ?? -1);
        $lastSeat = (int) ($ctx['lastSeat'] ?? -1);
        $maxPlayers = (int) ($ctx['maxPlayers'] ?? LzConsts::MAX_PLAYERS);
        $counts = is_array($ctx['seatCounts'] ?? null) ? $ctx['seatCounts'] : [];
        $role = self::farmerRole($mySeat, $landlordSeat, $maxPlayers);
        $landlordLeft = (int) ($counts[$landlordSeat] ?? 99);
        $threat = ! empty($ctx['threat']) || $landlordLeft <= 2;

        if ($role === self::ROLE_LANDLORD) {
            return self::decideAsLandlord($hand, $mustLead ? [] : $lastCards, $mustLead);
        }

        $lastFromLandlord = ! $mustLead && $lastSeat === $landlordSeat;
        $lastFromPartner = ! $mustLead
            && $lastSeat >= 0
            && $lastSeat !== $mySeat
            && $lastSeat !== $landlordSeat;
        if (! $lastFromPartner && ! empty($ctx['partnerLead'])) {
            $lastFromPartner = true;
        }

        if ($role === self::ROLE_NEXT) {
            return self::decideAsNextFarmer(
                $hand,
                $lastCards,
                $mustLead,
                $lastFromLandlord,
                $lastFromPartner,
                $threat
            );
        }

        return self::decideAsPrevFarmer(
            $hand,
            $lastCards,
            $mustLead,
            $lastFromLandlord,
            $lastFromPartner,
            $threat
        );
    }

    /**
     * @param list<string> $hand
     * @param list<string> $lastCards
     * @return array{action:string,cards:list<string>}
     */
    private static function decideAsLandlord(array $hand, array $lastCards, bool $mustLead): array
    {
        if ($mustLead || $lastCards === []) {
            $cards = LaiziCard::suggestPlay($hand, [], ['threat' => false, 'partnerLead' => false]);
            if ($cards === []) {
                $cards = LaiziCard::suggestPlay($hand, [], ['threat' => true, 'partnerLead' => false]);
            }

            return self::playOrPass($cards);
        }

        $cards = self::beat($hand, $lastCards, false);
        if ($cards === []) {
            $cards = self::beat($hand, $lastCards, true);
        }

        return self::playOrPass($cards);
    }

    /**
     * 农民下家：顶牌压制地主
     *
     * @param list<string> $hand
     * @param list<string> $lastCards
     * @return array{action:string,cards:list<string>}
     */
    private static function decideAsNextFarmer(
        array $hand,
        array $lastCards,
        bool $mustLead,
        bool $lastFromLandlord,
        bool $lastFromPartner,
        bool $threat,
    ): array {
        if ($mustLead || $lastCards === []) {
            return self::playOrPass(LaiziCard::suggestPlay($hand, [], [
                'threat'      => $threat,
                'partnerLead' => false,
            ]));
        }

        if ($lastFromPartner && ! $threat) {
            return ['action' => self::ACTION_PASS, 'cards' => []];
        }

        if ($lastFromLandlord) {
            $cards = self::pressLandlordAsNext($hand, $lastCards);
            if ($cards === []) {
                $cards = self::beat($hand, $lastCards, true);
            }

            return self::playOrPass($cards);
        }

        return self::playOrPass(self::beat($hand, $lastCards, $threat));
    }

    /**
     * @param list<string> $hand
     * @param list<string> $lastCards
     * @return list<string>
     */
    private static function pressLandlordAsNext(array $hand, array $lastCards): array
    {
        [$lastType, $lastWeight] = LaiziCard::analyzeCards($lastCards);
        if ($lastType === LaiziCard::CARD_TYPE_INVALID
            || $lastType === LaiziCard::CARD_TYPE_ROCKET
        ) {
            return [];
        }

        if ($lastType === LaiziCard::CARD_TYPE_SINGLE && $lastWeight <= self::SMALL_SINGLE_MAX) {
            $hit = self::pickSinglePress($hand, $lastWeight);
            if ($hit !== []) {
                return $hit;
            }
        }

        if ($lastType === LaiziCard::CARD_TYPE_PAIR && $lastWeight <= self::SMALL_PAIR_MAX) {
            // 先硬对 >9，再允许软对（癞子穷举变牌）
            $hit = self::pickHardPairAbove($hand, max($lastWeight, self::SMALL_PAIR_MAX));
            if ($hit !== []) {
                return $hit;
            }
            $soft = self::beat($hand, $lastCards, false);
            if ($soft !== []) {
                return $soft;
            }
        }

        return self::beat($hand, $lastCards, false);
    }

    /**
     * 农民上家：喂队友、不卡大牌
     *
     * @param list<string> $hand
     * @param list<string> $lastCards
     * @return array{action:string,cards:list<string>}
     */
    private static function decideAsPrevFarmer(
        array $hand,
        array $lastCards,
        bool $mustLead,
        bool $lastFromLandlord,
        bool $lastFromPartner,
        bool $threat,
    ): array {
        if ($mustLead || $lastCards === []) {
            $feed = self::pickSmallSingleFeed($hand);
            if ($feed !== []) {
                return ['action' => self::ACTION_PLAY, 'cards' => $feed];
            }

            return self::playOrPass(LaiziCard::suggestPlay($hand, [], [
                'threat'      => $threat,
                'partnerLead' => false,
            ]));
        }

        if ($lastFromPartner) {
            [$partnerType, $partnerWeight] = LaiziCard::analyzeCards($lastCards);
            if ($partnerType !== LaiziCard::CARD_TYPE_INVALID
                && $partnerWeight >= self::PARTNER_BIG_WEIGHT
            ) {
                return ['action' => self::ACTION_PASS, 'cards' => []];
            }
            if (! $threat) {
                return ['action' => self::ACTION_PASS, 'cards' => []];
            }
        }

        return self::playOrPass(self::beat($hand, $lastCards, $threat && $lastFromLandlord));
    }

    /**
     * 管牌：硬牌→软牌→软炸→硬炸→王炸
     *
     * @param list<string> $hand
     * @param list<string> $lastCards
     * @return list<string>
     */
    private static function beat(array $hand, array $lastCards, bool $allowBomb): array
    {
        return LaiziCard::suggestBeatByCost($hand, $lastCards, $allowBomb);
    }

    /**
     * @param list<string> $hand
     * @return list<string>
     */
    private static function pickSinglePress(array $hand, int $lastWeight): array
    {
        $map = self::groupNatural($hand);

        for ($p = max(self::WEIGHT_J, $lastWeight + 1); $p <= self::WEIGHT_A; $p++) {
            if (! empty($map[$p][0])) {
                return [$map[$p][0]];
            }
        }
        foreach ([self::WEIGHT_2, self::WEIGHT_SJOKER, self::WEIGHT_BJOKER] as $p) {
            if ($p > $lastWeight && ! empty($map[$p][0])) {
                return [$map[$p][0]];
            }
        }
        $sorted = [];
        foreach ($hand as $code) {
            if (! LaiziCard::isWild($code)) {
                $sorted[] = $code;
            }
        }
        LaiziCard::sortCards($sorted);
        foreach ($sorted as $code) {
            if (LaiziCard::toPoint($code) > $lastWeight
                && LaiziCard::analyzeCards([$code])[0] === LaiziCard::CARD_TYPE_SINGLE
            ) {
                return [$code];
            }
        }

        return [];
    }

    /**
     * @param list<string> $hand
     * @return list<string>
     */
    private static function pickHardPairAbove(array $hand, int $minWeight): array
    {
        $map = self::groupNatural($hand);
        foreach ($map as $point => $codes) {
            if ((int) $point <= $minWeight) {
                continue;
            }
            if (count($codes) >= 2) {
                return array_slice($codes, 0, 2);
            }
        }

        return [];
    }

    /**
     * 喂牌：小单 3–10（不拆对/炸，不用癞子）
     *
     * @param list<string> $hand
     * @return list<string>
     */
    private static function pickSmallSingleFeed(array $hand): array
    {
        $map = self::groupNatural($hand);
        foreach ([3, 4, 5, 6, 7, 8, 9, 10] as $p) {
            if (isset($map[$p]) && count($map[$p]) === 1) {
                return [$map[$p][0]];
            }
        }

        return [];
    }

    /**
     * @param list<string> $hand
     * @return array<int, list<string>>
     */
    private static function groupNatural(array $hand): array
    {
        $map = [];
        foreach ($hand as $code) {
            if (LaiziCard::isWild($code)) {
                continue;
            }
            $p = LaiziCard::toPoint($code);
            if ($p <= 0) {
                continue;
            }
            $map[$p][] = $code;
        }
        ksort($map);

        return $map;
    }

    /**
     * @param list<string> $cards
     * @return array{action:string,cards:list<string>}
     */
    private static function playOrPass(array $cards): array
    {
        return $cards === []
            ? ['action' => self::ACTION_PASS, 'cards' => []]
            : ['action' => self::ACTION_PLAY, 'cards' => $cards];
    }
}
