<?php

declare(strict_types=1);

namespace App\Game\Contract;

/**
 * 玩法超时策略：解析阶段、到点兜底、令牌校验中的「当前行动者」
 *
 * 内核 {@see \App\Game\Platform\Timeout\RoomTimeoutService} 负责 Timer + Redis 令牌 + wait 关房；
 * 各游戏 Module 实现本接口即可接入。
 */
interface GameTimeoutHandlerInterface
{
    /**
     * 解析进行中局的超时阶段。
     * 返回 null 表示当前无局内行动，内核按 wait 挂表。
     */
    public function resolveInGamePhase(int $roomId, int $requiredPlayers = 0): ?TimeoutPhase;

    /**
     * 令牌消费时校验：阶段仍一致且仍轮到 expectPlayerId（0 表示不校验玩家）。
     */
    public function actorStillMatches(int $roomId, string $phase, int $expectPlayerId): bool;

    /**
     * 局内超时兜底（自动叫/出等）。
     *
     * @return bool true=已处理；false=未处理成功，内核应重新挂表
     */
    public function onTimeout(int $roomId, string $phase, int $requiredPlayers): bool;

    /**
     * 点「离开」立刻托管时：若正轮到该玩家，马上代打一手。
     */
    public function tryImmediateAutoPlay(int $roomId, int $playerId): void;

    /**
     * wait 关房时是否从未真正打完过一局（用于解散文案区分）。
     */
    public function wasNeverPlayed(int $roomId): bool;
}
