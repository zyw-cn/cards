<?php

declare(strict_types=1);

namespace App\Game\Contract;

/**
 * 房间超时阶段快照（由各玩法 Handler 解析后交给内核挂表）
 */
final class TimeoutPhase
{
    /** 房间空闲等待（内核关房，与玩法无关） */
    public const WAIT = 'wait';

    public function __construct(
        public readonly string $name,
        public readonly int $seconds,
        public readonly int $expectPlayerId = 0,
    ) {
    }

    public function isWait(): bool
    {
        return $this->name === self::WAIT;
    }
}
