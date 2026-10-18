<?php

declare(strict_types=1);

namespace App\Game\Zjh;

use App\Constants\GameType;
use App\Game\Contract\GameActionInterface;
use App\Game\Contract\GameModuleInterface;
use App\Game\Contract\GameTimeoutHandlerInterface;
use App\Game\Contract\GameTrustAiInterface;
use App\Game\Contract\ResumeBuilderInterface;
use App\Game\Zjh\Action\CallAction;
use App\Game\Zjh\Action\CompareAction;
use App\Game\Zjh\Action\FoldAction;
use App\Game\Zjh\Action\JoinAction;
use App\Game\Zjh\Action\LookAction;
use App\Game\Zjh\Action\RaiseAction;
use App\Game\Zjh\Action\StartGameAction;
use App\Game\Zjh\Action\TrustAction;
use App\Game\Zjh\Action\UntrustAction;
use App\Game\Zjh\Ai\ZajinhuaAi;
use App\Game\Zjh\Timeout\ZjhTimeoutHandler;
use function Hyperf\Support\make;

/**
 * 炸金花 Module 门面
 *
 * 身份：game_type=3 ↔ route 前缀 Zjh
 *
 * 流程：Join → StartGame(满员发牌+底注) → Look/Call/Raise/Fold/Compare → 一人独活结算
 */
final class ZjhModule implements GameModuleInterface
{
    public function gameType(): int
    {
        return GameType::ZJH;
    }

    public function code(): string
    {
        return 'Zjh';
    }

    public function maxPlayers(): int
    {
        return ZjhConsts::MAX_PLAYERS;
    }

    public function action(string $name): ?GameActionInterface
    {
        $key = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $name) ?? '');

        return match ($key) {
            'join'                       => make(JoinAction::class),
            'start', 'startgame', 'ready'=> make(StartGameAction::class),
            'look', 'see'                => make(LookAction::class),
            'call', 'follow'             => make(CallAction::class),
            'raise', 'add'               => make(RaiseAction::class),
            'fold', 'giveup'             => make(FoldAction::class),
            'compare', 'pk', 'versus'    => make(CompareAction::class),
            'trust'                      => make(TrustAction::class),
            'untrust'                    => make(UntrustAction::class),
            default                      => null,
        };
    }

    public function timeoutHandler(): GameTimeoutHandlerInterface
    {
        return make(ZjhTimeoutHandler::class);
    }

    public function trustAi(): GameTrustAiInterface
    {
        return make(ZajinhuaAi::class);
    }

    public function resumeBuilder(): ResumeBuilderInterface
    {
        return make(ResumeBuilder::class);
    }
}
