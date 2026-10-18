<?php

declare(strict_types=1);

namespace App\Game\Hzmj;

use App\Constants\GameType;
use App\Game\Contract\GameActionInterface;
use App\Game\Contract\GameModuleInterface;
use App\Game\Contract\GameTimeoutHandlerInterface;
use App\Game\Contract\GameTrustAiInterface;
use App\Game\Contract\ResumeBuilderInterface;
use App\Game\Hzmj\Action\GrabBankerAction;
use App\Game\Hzmj\Action\JoinAction;
use App\Game\Hzmj\Action\OpAction;
use App\Game\Hzmj\Action\StartGameAction;
use App\Game\Hzmj\Action\TrustAction;
use App\Game\Hzmj\Action\UntrustAction;
use App\Game\Hzmj\Ai\RedChangMahjongAi;
use App\Game\Hzmj\Timeout\HzmjTimeoutHandler;
use function Hyperf\Support\make;

/**
 * 红中麻将 Module 门面
 *
 * 身份：game_type=7 ↔ route 前缀 Hzmj
 *
 * 一局流程：Join → StartGame(满员) → Grab(抢庄) → 发牌 → Op → 结算
 */
final class HzmjModule implements GameModuleInterface
{
    public function gameType(): int
    {
        return GameType::HZMJ;
    }

    public function code(): string
    {
        return 'Hzmj';
    }

    public function maxPlayers(): int
    {
        return HzmjConsts::MAX_PLAYERS;
    }

    public function action(string $name): ?GameActionInterface
    {
        $key = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $name) ?? '');

        return match ($key) {
            'join'               => make(JoinAction::class),
            'start', 'startgame' => make(StartGameAction::class),
            'grab', 'grabbanker', 'banker' => make(GrabBankerAction::class),
            'op'                 => make(OpAction::class),
            'trust'              => make(TrustAction::class),
            'untrust'            => make(UntrustAction::class),
            default              => null,
        };
    }

    public function timeoutHandler(): GameTimeoutHandlerInterface
    {
        return make(HzmjTimeoutHandler::class);
    }

    public function trustAi(): GameTrustAiInterface
    {
        return make(RedChangMahjongAi::class);
    }

    public function resumeBuilder(): ResumeBuilderInterface
    {
        return make(ResumeBuilder::class);
    }
}
