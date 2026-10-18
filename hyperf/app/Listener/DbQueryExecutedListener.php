<?php

declare(strict_types=1);

namespace App\Listener;

use App\Facades\Log;
use Hyperf\Database\Events\QueryExecuted;
use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;

#[Listener]
class DbQueryExecutedListener implements ListenerInterface
{
    public function listen(): array
    {
        return [
            QueryExecuted::class,
        ];
    }

    public function process(object $event): void
    {
        if ($event instanceof QueryExecuted) {
            $sql = $event->sql;
            if (!empty($event->bindings)) {
                foreach ($event->bindings as $binding) {
                    $value = is_numeric($binding) ? $binding : "'{$binding}'";
                    $sql = preg_replace('/\?/', (string) $value, $sql, 1);
                }
            }

            Log::sql($sql, [
                'time'       => $event->time . 'ms',
                'connection' => $event->connectionName,
            ]);
        }
    }
}
