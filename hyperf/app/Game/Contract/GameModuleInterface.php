<?php

declare(strict_types=1);

namespace App\Game\Contract;

/**
 * 玩法 Module（目录即 Module，如 App\Game\Dou）
 *
 * game_type（DB）与 code（WS route 前缀）在 Module 内写死一次，
 * 由 GameRegistry 做双向查找。
 */
interface GameModuleInterface extends RoutableModuleInterface
{
    /** 对应 room.game_type */
    public function gameType(): int;

    public function maxPlayers(): int;

    public function timeoutHandler(): GameTimeoutHandlerInterface;

    public function resumeBuilder(): ResumeBuilderInterface;

    /** 托管 AI：超时代打 / Trust 立即代打统一入口 */
    public function trustAi(): GameTrustAiInterface;
}
