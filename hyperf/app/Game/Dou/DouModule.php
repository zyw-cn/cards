<?php

declare(strict_types=1);

namespace App\Game\Dou;

use App\Constants\GameType;
use App\Game\Contract\GameActionInterface;
use App\Game\Contract\GameModuleInterface;
use App\Game\Contract\GameTimeoutHandlerInterface;
use App\Game\Contract\GameTrustAiInterface;
use App\Game\Contract\ResumeBuilderInterface;
use App\Game\Dou\Action\BidAction;
use App\Game\Dou\Action\DoubleAction;
use App\Game\Dou\Action\JoinAction;
use App\Game\Dou\Action\PlayAction;
use App\Game\Dou\Action\StartGameAction;
use App\Game\Dou\Action\TrustAction;
use App\Game\Dou\Action\UntrustAction;
use App\Game\Dou\Ai\LandlordTrustAi;
use App\Game\Dou\Timeout\DouTimeoutHandler;
use function Hyperf\Support\make;

/**
 * 斗地主 Module 门面（目录 App\Game\Dou 即本 Module）
 *
 * 身份：game_type=1 ↔ route 前缀 Dou
 */
final class DouModule implements GameModuleInterface
{
    public function gameType(): int
    {
        return GameType::LANDLORD;
    }

    public function code(): string
    {
        return 'Dou';
    }

    public function maxPlayers(): int
    {
        return DouConsts::MAX_PLAYERS;
    }

    public function action(string $name): ?GameActionInterface
    {
        $key = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $name) ?? '');

        return match ($key) {
            'join'               => make(JoinAction::class),
            'start', 'startgame' => make(StartGameAction::class),
            'bid'                => make(BidAction::class),
            'double'             => make(DoubleAction::class),
            'play'               => make(PlayAction::class),
            'trust'              => make(TrustAction::class),
            'untrust'            => make(UntrustAction::class),
            default              => null,
        };
    }

    public function timeoutHandler(): GameTimeoutHandlerInterface
    {
        return make(DouTimeoutHandler::class);
    }

    public function trustAi(): GameTrustAiInterface
    {
        return make(LandlordTrustAi::class);
    }

    public function resumeBuilder(): ResumeBuilderInterface
    {
        return make(ResumeBuilder::class);
    }
}
