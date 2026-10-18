<?php

declare(strict_types=1);

namespace App\Game\Lz;

use App\Constants\GameType;
use App\Game\Contract\GameActionInterface;
use App\Game\Contract\GameModuleInterface;
use App\Game\Contract\GameTimeoutHandlerInterface;
use App\Game\Contract\GameTrustAiInterface;
use App\Game\Contract\ResumeBuilderInterface;
use App\Game\Lz\Action\BidAction;
use App\Game\Lz\Action\DoubleAction;
use App\Game\Lz\Action\JoinAction;
use App\Game\Lz\Action\PlayAction;
use App\Game\Lz\Action\StartGameAction;
use App\Game\Lz\Action\TrustAction;
use App\Game\Lz\Action\UntrustAction;
use App\Game\Lz\Ai\LaiziLandlordAi;
use App\Game\Lz\Timeout\LzTimeoutHandler;
use function Hyperf\Support\make;

/**
 * 癞子斗地主 Module 门面（目录 App\Game\Lz 即本 Module）
 *
 * 身份：game_type=5 ↔ route 前缀 Lz
 */
final class LzModule implements GameModuleInterface
{
    public function gameType(): int
    {
        return GameType::LAIZI_LANDLORD;
    }

    public function code(): string
    {
        return 'Lz';
    }

    public function maxPlayers(): int
    {
        return LzConsts::MAX_PLAYERS;
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
        return make(LzTimeoutHandler::class);
    }

    public function trustAi(): GameTrustAiInterface
    {
        return make(LaiziLandlordAi::class);
    }

    public function resumeBuilder(): ResumeBuilderInterface
    {
        return make(ResumeBuilder::class);
    }
}
