<?php

declare(strict_types=1);

namespace App\Game\Dou\Ai;

use App\Game\Contract\GameTrustAiInterface;
use App\Game\Contract\TrustDecision;
use App\Game\Dou\Card\LandlordCard;
use App\Game\Dou\DouConsts;
use App\Game\Support\LandlordSeatRole;

/**
 * 经典斗地主 AI 托管
 *
 * 1. 叫抢：托管一律 PASS_CALL
 * 2. 农民下家（门板）：顶牌，不让地主轻松过小牌
 * 3. 农民上家（守门员）：喂队友、不卡队友大牌
 * 4. 地主：能管就管；首出优先最长连牌，炸弹/王炸留作末手防御
 */
final class LandlordTrustAi implements GameTrustAiInterface
{
    public function decide(string $phase, array $context = []): TrustDecision
    {
        $phase = strtolower(trim($phase));
        if (in_array($phase, ['bid', 'grab', 'call'], true)) {
            $bid = self::decideBid();

            return TrustDecision::passCall((int) ($bid['score'] ?? 0));
        }

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

    public const ACTION_PASS_CALL = 'PASS_CALL';

    public const ACTION_PASS = 'PASS';

    public const ACTION_PLAY = 'PLAY';

    /** 地主下家 / 门板位 */
    public const ROLE_NEXT = LandlordSeatRole::NEXT;

    /** 地主上家 / 守门员位 */
    public const ROLE_PREV = LandlordSeatRole::PREV;

    public const ROLE_LANDLORD = LandlordSeatRole::LANDLORD;

    /** J=11 … A=14，中等价值顶牌区间 */
    private const WEIGHT_J = 11;

    private const WEIGHT_A = 14;

    private const WEIGHT_2 = 15;

    private const WEIGHT_SJOKER = 16;

    private const WEIGHT_BJOKER = 17;

    /** 小单张上限（3–10） */
    private const SMALL_SINGLE_MAX = 10;

    /** 小对子上限；拦截要求对子点数 > 9 */
    private const SMALL_PAIR_MAX = 9;

    /** 队友大牌阈值：权重大于等于 11（J 及以上）则上家必过 */
    private const PARTNER_BIG_WEIGHT = 11;

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

    /** 下家=(landlord+1)%3，上家=(landlord+2)%3 */
    public static function farmerRole(int $mySeat, int $landlordSeat, int $maxPlayers = DouConsts::MAX_PLAYERS): string
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
     *   maxPlayers?: int
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

        $finish = LandlordCard::suggestFinishPlay($hand, $lastCards);
        if ($finish !== []) {
            return ['action' => self::ACTION_PLAY, 'cards' => $finish];
        }

        $mustLead = ! empty($ctx['mustLead']) || $lastCards === [];
        $mySeat = (int) ($ctx['mySeat'] ?? -1);
        $landlordSeat = (int) ($ctx['landlordSeat'] ?? -1);
        $lastSeat = (int) ($ctx['lastSeat'] ?? -1);
        $maxPlayers = (int) ($ctx['maxPlayers'] ?? DouConsts::MAX_PLAYERS);
        $counts = is_array($ctx['seatCounts'] ?? null) ? $ctx['seatCounts'] : [];
        $role = self::farmerRole($mySeat, $landlordSeat, $maxPlayers);
        $landlordLeft = (int) ($counts[$landlordSeat] ?? 99);
        $threat = $landlordLeft <= 2;

        if ($role === self::ROLE_LANDLORD) {
            return self::decideAsLandlord($hand, $mustLead ? [] : $lastCards, $mustLead);
        }

        $lastFromLandlord = ! $mustLead && $lastSeat === $landlordSeat;
        $lastFromPartner = ! $mustLead
            && $lastSeat >= 0
            && $lastSeat !== $mySeat
            && $lastSeat !== $landlordSeat;

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
     * 地主：能管就管（最小刚好大过）；首出优先最长连子/连对/飞机，炸弹王炸留到末两手
     *
     * @param list<string> $hand
     * @param list<string> $lastCards
     * @return array{action:string,cards:list<string>}
     */
    private static function decideAsLandlord(array $hand, array $lastCards, bool $mustLead): array
    {
        if ($mustLead || $lastCards === []) {
            // suggestPlay 首出已去掉炸弹/王炸，并优先最长顺/连对/飞机
            $cards = LandlordCard::suggestPlay($hand, [], [
                'threat'      => false,
                'partnerLead' => false,
            ]);
            // 仅当手牌已只剩炸弹/王炸（末两手级）才允许甩炸首出
            if ($cards === []) {
                $cards = LandlordCard::suggestPlay($hand, [], [
                    'threat'      => true,
                    'partnerLead' => false,
                ]);
            }

            return $cards === []
                ? ['action' => self::ACTION_PASS, 'cards' => []]
                : ['action' => self::ACTION_PLAY, 'cards' => $cards];
        }

        // 跟牌：同型最小能管则管；管不上再考虑炸
        $cards = LandlordCard::suggestPlay($hand, $lastCards, [
            'threat'      => false,
            'partnerLead' => false,
        ]);
        if ($cards === []) {
            $cards = LandlordCard::suggestPlay($hand, $lastCards, [
                'threat'      => true,
                'partnerLead' => false,
            ]);
        }

        return $cards === []
            ? ['action' => self::ACTION_PASS, 'cards' => []]
            : ['action' => self::ACTION_PLAY, 'cards' => $cards];
    }

    /**
     * 农民下家（门板）：顶牌压制地主
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
            $cards = LandlordCard::suggestPlay($hand, [], [
                'threat'      => $threat,
                'partnerLead' => false,
            ]);

            return ['action' => self::ACTION_PLAY, 'cards' => $cards];
        }

        // 队友出牌：让路（地主报危时仍可接管）
        if ($lastFromPartner && ! $threat) {
            return ['action' => self::ACTION_PASS, 'cards' => []];
        }

        if ($lastFromLandlord) {
            $cards = self::pressLandlordAsNext($hand, $lastCards);
            if ($cards !== []) {
                return ['action' => self::ACTION_PLAY, 'cards' => $cards];
            }
            // 小牌顶不上时甩炸夺权
            $cards = LandlordCard::suggestPlay($hand, $lastCards, [
                'threat'      => true,
                'partnerLead' => false,
            ]);

            return $cards === []
                ? ['action' => self::ACTION_PASS, 'cards' => []]
                : ['action' => self::ACTION_PLAY, 'cards' => $cards];
        }

        $cards = LandlordCard::suggestPlay($hand, $lastCards, [
            'threat'      => $threat,
            'partnerLead' => $lastFromPartner,
        ]);

        return $cards === []
            ? ['action' => self::ACTION_PASS, 'cards' => []]
            : ['action' => self::ACTION_PLAY, 'cards' => $cards];
    }

    /**
     * 门板位对地主的顶牌细则
     *
     * @param list<string> $hand
     * @param list<string> $lastCards
     * @return list<string>
     */
    private static function pressLandlordAsNext(array $hand, array $lastCards): array
    {
        [$lastType, $lastWeight] = LandlordCard::analyzeCards($lastCards);
        if ($lastType === LandlordCard::CARD_TYPE_INVALID
            || $lastType === LandlordCard::CARD_TYPE_ROCKET
        ) {
            return [];
        }

        // 单张顶牌：小单 → J-A 必须顶；必要时 2 / 大王卡死
        if ($lastType === LandlordCard::CARD_TYPE_SINGLE && $lastWeight <= self::SMALL_SINGLE_MAX) {
            $hit = self::pickSinglePress($hand, $lastWeight);
            if ($hit !== []) {
                return $hit;
            }
        }

        // 对子顶牌：小对 → 必须用 >9 的对子拦截
        if ($lastType === LandlordCard::CARD_TYPE_PAIR && $lastWeight <= self::SMALL_PAIR_MAX) {
            $hit = self::pickPairAbove($hand, max($lastWeight, self::SMALL_PAIR_MAX));
            if ($hit !== []) {
                return $hit;
            }
        }

        // 其余：最小同型能管则管
        return LandlordCard::suggestPlay($hand, $lastCards, [
            'threat'      => false,
            'partnerLead' => false,
        ]);
    }

    /**
     * 小单顶牌：优先 J–A，再 2，再小王，再大王
     *
     * @param list<string> $hand
     * @return list<string>
     */
    private static function pickSinglePress(array $hand, int $lastWeight): array
    {
        $map = self::groupCodes($hand);

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
        // 没有中大牌时，仍用最小能压的单张
        $sorted = $hand;
        LandlordCard::sortCards($sorted);
        foreach ($sorted as $code) {
            if (LandlordCard::toPoint($code) > $lastWeight) {
                return [$code];
            }
        }

        return [];
    }

    /**
     * 取点数严格大于 $minWeight 的最小对子
     *
     * @param list<string> $hand
     * @return list<string>
     */
    private static function pickPairAbove(array $hand, int $minWeight): array
    {
        $map = self::groupCodes($hand);
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
     * 农民上家（守门员）：喂队友、不卡大牌、自己跑牌
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
        // 首出：优先小单（3/4/5）喂下家队友
        if ($mustLead || $lastCards === []) {
            $feed = self::pickSmallSingleFeed($hand);
            if ($feed !== []) {
                return ['action' => self::ACTION_PLAY, 'cards' => $feed];
            }
            $cards = LandlordCard::suggestPlay($hand, [], [
                'threat'      => $threat,
                'partnerLead' => false,
            ]);

            return ['action' => self::ACTION_PLAY, 'cards' => $cards];
        }

        // 绝对不卡队友：队友出 J 及以上且已压住上手 → 必过
        if ($lastFromPartner) {
            [$partnerType, $partnerWeight] = LandlordCard::analyzeCards($lastCards);
            if ($partnerType !== LandlordCard::CARD_TYPE_INVALID
                && $partnerWeight >= self::PARTNER_BIG_WEIGHT
            ) {
                return ['action' => self::ACTION_PASS, 'cards' => []];
            }
            if (! $threat) {
                return ['action' => self::ACTION_PASS, 'cards' => []];
            }
        }

        // 地主出牌：最小牌顶一下
        $cards = LandlordCard::suggestPlay($hand, $lastCards, [
            'threat'      => $threat && $lastFromLandlord,
            'partnerLead' => $lastFromPartner,
        ]);

        return $cards === []
            ? ['action' => self::ACTION_PASS, 'cards' => []]
            : ['action' => self::ACTION_PLAY, 'cards' => $cards];
    }

    /**
     * 喂牌：优先散装小单 3→5，再扩展到 10 以下单张（不拆对/炸）
     *
     * @param list<string> $hand
     * @return list<string>
     */
    private static function pickSmallSingleFeed(array $hand): array
    {
        $map = self::groupCodes($hand);
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
    private static function groupCodes(array $hand): array
    {
        $map = [];
        foreach ($hand as $code) {
            $p = LandlordCard::toPoint($code);
            if ($p <= 0) {
                continue;
            }
            $map[$p][] = $code;
        }
        ksort($map);

        return $map;
    }
}
