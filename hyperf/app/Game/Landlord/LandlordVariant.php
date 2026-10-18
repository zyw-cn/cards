<?php

declare(strict_types=1);

namespace App\Game\Landlord;

use App\Game\Dou\DealBootstrap as DouDealBootstrap;
use App\Game\Dou\DouConsts;
use App\Game\Dou\RoundState as DouRoundState;
use App\Game\Dou\Rule\BidRule as DouBidRule;
use App\Game\Dou\Action\StartGameAction as DouStartGameAction;
use App\Game\Dou\Timeout\DouTimeoutHandler;
use App\Game\Lz\DealBootstrap as LzDealBootstrap;
use App\Game\Lz\LzConsts;
use App\Game\Lz\RoundState as LzRoundState;
use App\Game\Lz\Rule\BidRule as LzBidRule;
use App\Game\Lz\Action\StartGameAction as LzStartGameAction;
use App\Game\Lz\Timeout\LzTimeoutHandler;

/**
 * 斗地主 / 癞子斗地主 变体配置（叫抢、加倍共享逻辑的差异点）
 */
final class LandlordVariant
{
    /**
     * @param class-string $roundState
     * @param class-string $bidRule
     * @param class-string $dealBootstrap
     * @param class-string $startGameAction
     * @param class-string $timeoutHandler
     * @param list<int>    $doubleScores
     */
    public function __construct(
        public readonly string $tag,
        public readonly string $roundState,
        public readonly string $bidRule,
        public readonly string $dealBootstrap,
        public readonly string $startGameAction,
        public readonly string $timeoutHandler,
        public readonly string $phaseGrab,
        public readonly string $phaseDouble,
        public readonly string $phasePlay,
        public readonly array $doubleScores,
        public readonly string $callbackBid,
        public readonly string $callbackDouble,
    ) {
    }

    public static function dou(): self
    {
        return new self(
            tag: 'Dou',
            roundState: DouRoundState::class,
            bidRule: DouBidRule::class,
            dealBootstrap: DouDealBootstrap::class,
            startGameAction: DouStartGameAction::class,
            timeoutHandler: DouTimeoutHandler::class,
            phaseGrab: DouConsts::PHASE_GRAB,
            phaseDouble: DouConsts::PHASE_DOUBLE,
            phasePlay: DouConsts::PHASE_PLAY,
            doubleScores: DouConsts::DOUBLE_SCORES,
            callbackBid: 'bid',
            callbackDouble: 'double',
        );
    }

    public static function lz(): self
    {
        return new self(
            tag: 'Lz',
            roundState: LzRoundState::class,
            bidRule: LzBidRule::class,
            dealBootstrap: LzDealBootstrap::class,
            startGameAction: LzStartGameAction::class,
            timeoutHandler: LzTimeoutHandler::class,
            phaseGrab: LzConsts::PHASE_GRAB,
            phaseDouble: LzConsts::PHASE_DOUBLE,
            phasePlay: LzConsts::PHASE_PLAY,
            doubleScores: LzConsts::DOUBLE_SCORES,
            callbackBid: 'bid',
            callbackDouble: 'double',
        );
    }
}
