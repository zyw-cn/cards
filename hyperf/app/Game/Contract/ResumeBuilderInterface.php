<?php

declare(strict_types=1);

namespace App\Game\Contract;

/**
 * 断线重连快照（各 Module 自行实现）
 */
interface ResumeBuilderInterface
{
    /**
     * @return array<string, mixed>|null
     */
    public function build(int $roomId, int $playerId): ?array;
}
