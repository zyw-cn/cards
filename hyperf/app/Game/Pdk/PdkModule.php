<?php

declare(strict_types=1);

namespace App\Game\Pdk;

use App\Constants\GameType;
use App\Game\Contract\GameActionInterface;
use App\Game\Contract\GameModuleInterface;
use App\Game\Contract\GameTimeoutHandlerInterface;
use App\Game\Contract\GameTrustAiInterface;
use App\Game\Contract\ResumeBuilderInterface;
use App\Game\Pdk\Action\DoubleAction;
use App\Game\Pdk\Action\JoinAction;
use App\Game\Pdk\Action\PlayAction;
use App\Game\Pdk\Action\StartGameAction;
use App\Game\Pdk\Action\TrustAction;
use App\Game\Pdk\Action\UntrustAction;
use App\Game\Pdk\Ai\PdkAi;
use App\Game\Pdk\Timeout\PdkTimeoutHandler;
use function Hyperf\Support\make;

/**
 * 跑得快 Module 门面
 *
 * 身份：game_type=4 ↔ route 前缀 Pdk
 *
 * 一局流程：Join → StartGame(满 3 准备) → 发牌 → Double → Play(持红桃3者先出，不必带出) → 结算
 */
final class PdkModule implements GameModuleInterface
{
    public function gameType(): int
    {
        return GameType::PAODEKUAI;
    }

    public function code(): string
    {
        return 'Pdk';
    }

    public function maxPlayers(): int
    {
        return PdkConsts::MAX_PLAYERS;
    }

    public function action(string $name): ?GameActionInterface
    {
        $key = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $name) ?? '');

        return match ($key) {
            'join'               => make(JoinAction::class),
            'start', 'startgame' => make(StartGameAction::class),
            'double'             => make(DoubleAction::class),
            'play'               => make(PlayAction::class),
            'trust'              => make(TrustAction::class),
            'untrust'            => make(UntrustAction::class),
            default              => null,
        };
    }

    public function timeoutHandler(): GameTimeoutHandlerInterface
    {
        return make(PdkTimeoutHandler::class);
    }

    public function trustAi(): GameTrustAiInterface
    {
        return make(PdkAi::class);
    }

    public function resumeBuilder(): ResumeBuilderInterface
    {
        return make(ResumeBuilder::class);
    }
}
