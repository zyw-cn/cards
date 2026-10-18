<?php

declare(strict_types=1);

namespace App\Model\Game;

use App\Model\Model;

/**
 * @property int $id
 * @property string $name
 * @property string|null $create_at
 * @property string $bottom_points
 * @property int $status
 * @property string $sub_name
 * @property string|null $update_time
 * @property string $entry_points
 */
class RoomConfig extends Model
{
    protected ?string $table = 'room_config';

    public bool $timestamps = false;

    protected array $casts = [
        'id' => 'integer',
        'status' => 'integer',
    ];
}
