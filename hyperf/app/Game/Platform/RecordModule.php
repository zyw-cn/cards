<?php

declare(strict_types=1);

namespace App\Game\Platform;

use App\Game\Contract\GameActionInterface;
use App\Game\Contract\RoutableModuleInterface;
use App\Game\Platform\Action\RecordGetAction;
use App\Game\Platform\Action\RecordListAction;
use function Hyperf\Support\make;

/**
 * 战绩 / 录像 Module（路由前缀 Record）
 */
final class RecordModule implements RoutableModuleInterface
{
    public function code(): string
    {
        return 'Record';
    }

    public function action(string $name): ?GameActionInterface
    {
        $key = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $name) ?? '');

        return match ($key) {
            'list', 'lists', 'history' => make(RecordListAction::class),
            'get', 'detail', 'playback', 'query' => make(RecordGetAction::class),
            default => null,
        };
    }
}
