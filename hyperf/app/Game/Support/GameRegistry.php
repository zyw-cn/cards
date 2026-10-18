<?php

declare(strict_types=1);

namespace App\Game\Support;

use App\Facades\Log;
use App\Game\Contract\GameModuleInterface;
use App\Game\Contract\GameTrustAiInterface;
use App\Game\Contract\RoutableModuleInterface;
use App\Game\Dou\DouModule;
use App\Game\Lz\LzModule;
use App\Game\Niu\NiuModule;
use App\Game\Hzmj\HzmjModule;
use App\Game\Pdk\PdkModule;
use App\Game\Platform\RecordModule;
use App\Game\Platform\RoomModule;
use App\Game\Zjh\ZjhModule;
use function Hyperf\Support\make;

/**
 * Module 注册表
 *
 * - WS 业务：route 前缀（Dou / Niu / Room）→ getByCode
 * - 超时等无 route 场景：room.game_type → getByType
 * 各玩法业务互不复用，只在此登记 Module。
 */
class GameRegistry
{
    /** @var array<int, GameModuleInterface> */
    private array $byType = [];

    /** @var array<string, RoutableModuleInterface> */
    private array $byCode = [];

    private bool $booted = false;

    public function register(RoutableModuleInterface $module): void
    {
        $this->byCode[strtolower($module->code())] = $module;
        if ($module instanceof GameModuleInterface) {
            $this->byType[$module->gameType()] = $module;
        }
    }

    public function getByType(int $gameType): ?GameModuleInterface
    {
        $this->boot();

        return $this->byType[$gameType] ?? null;
    }

    public function getByCode(string $code): ?RoutableModuleInterface
    {
        $this->boot();
        $key = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');

        return $key === '' ? null : ($this->byCode[$key] ?? null);
    }

    /** 按 game_type 取托管 AI（无 Module 时返回 null） */
    public function trustAiByType(int $gameType): ?GameTrustAiInterface
    {
        return $this->getByType($gameType)?->trustAi();
    }

    private function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        try {
            $this->register(make(RoomModule::class));
            $this->register(make(RecordModule::class));
            $this->register(make(DouModule::class));
            $this->register(make(LzModule::class));
            $this->register(make(NiuModule::class));
            $this->register(make(ZjhModule::class));
            $this->register(make(PdkModule::class));
            $this->register(make(HzmjModule::class));
        } catch (\Throwable $e) {
            Log::error('GameRegistry 注册 Module 失败', ['error' => $e->getMessage()]);
        }
    }
}
