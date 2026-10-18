<?php

declare(strict_types=1);

namespace App\Game\Niu;

use App\Constants\GameType;
use App\Game\Contract\GameActionInterface;
use App\Game\Contract\GameModuleInterface;
use App\Game\Contract\GameTimeoutHandlerInterface;
use App\Game\Contract\GameTrustAiInterface;
use App\Game\Contract\ResumeBuilderInterface;
use App\Game\Niu\Action\BetAction;
use App\Game\Niu\Action\GrabBankerAction;
use App\Game\Niu\Action\JoinAction;
use App\Game\Niu\Action\ShowAction;
use App\Game\Niu\Action\StartGameAction;
use App\Game\Niu\Action\TrustAction;
use App\Game\Niu\Action\UntrustAction;
use App\Game\Niu\Ai\NiuTrustAi;
use App\Game\Niu\Timeout\NiuTimeoutHandler;
use function Hyperf\Support\make;

/**
 * 斗牛 Module 门面（目录 App\Game\Niu 即本 Module）
 *
 * 身份：game_type=2 ↔ route 前缀 Niu
 *
 * 一局流程：
 *   Join → StartGame(满员) → Grab(抢庄) → Bet(闲家下注) → 系统发牌 → Show(亮牌结算)
 *
 * 人数：Module::maxPlayers() 返回硬上限 10；
 * 实际入座/开局人数取房间 room.num（建房传入，2~10）。
 */
final class NiuModule implements GameModuleInterface
{
    public function gameType(): int
    {
        return GameType::NIU;
    }

    public function code(): string
    {
        return 'Niu';
    }

    /** 硬上限；Join / 超时内核会再与 room.num 取小 */
    public function maxPlayers(): int
    {
        return NiuConsts::MAX_PLAYERS;
    }

    public function action(string $name): ?GameActionInterface
    {
        // 去掉非字母数字后小写匹配，兼容 GrabBanker / grab_banker 等写法
        $key = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $name) ?? '');

        return match ($key) {
            'join'                         => make(JoinAction::class),
            'start', 'startgame'           => make(StartGameAction::class),
            'grab', 'grabbanker', 'banker' => make(GrabBankerAction::class),
            'bet'                          => make(BetAction::class),
            'show', 'reveal', 'settle'     => make(ShowAction::class),
            'trust'                        => make(TrustAction::class),
            'untrust'                      => make(UntrustAction::class),
            default                        => null,
        };
    }

    public function timeoutHandler(): GameTimeoutHandlerInterface
    {
        return make(NiuTimeoutHandler::class);
    }

    public function trustAi(): GameTrustAiInterface
    {
        return make(NiuTrustAi::class);
    }

    public function resumeBuilder(): ResumeBuilderInterface
    {
        return make(ResumeBuilder::class);
    }
}
