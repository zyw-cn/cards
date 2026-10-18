<?php

declare(strict_types=1);

namespace App\Game\Support;

use App\Constants\GameType;
use App\Facades\Log;
use App\Game\Contract\GameTimeoutHandlerInterface;
use App\Game\Dou\Timeout\DouTimeoutHandler;
use function Hyperf\Support\make;

/**
 * 按 game_type 解析玩法超时 Handler（委托 GameRegistry → Module）
 */
class GameTimeoutHandlerFactory
{
    public function forGameType(int $gameType): GameTimeoutHandlerInterface
    {
        $registry = make(GameRegistry::class);
        $module = $registry->getByType($gameType);
        if ($module !== null) {
            return $module->timeoutHandler();
        }

        Log::warning('未注册的 game_type，回退默认 Module 超时策略', ['game_type' => $gameType]);

        $fallback = $registry->getByType(GameType::LANDLORD);
        if ($fallback !== null) {
            return $fallback->timeoutHandler();
        }

        return make(DouTimeoutHandler::class);
    }
}
