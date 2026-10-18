<?php

declare(strict_types=1);

namespace App\Model\Game;

use App\Model\Model;

/**
 * @property int $id
 * @property int $game_round_id
 * @property int $seq
 * @property int $member_id
 * @property string $now_cards
 * @property string $cards
 * @property string $create_time
 */
class GamePlayCard extends Model
{
    protected ?string $table = 'game_play_card';

    public bool $timestamps = false;

    protected array $fillable = [
        'game_round_id',
        'seq',
        'member_id',
        'now_cards',
        'cards',
        'create_time',
    ];

    protected array $casts = [
        'id' => 'integer',
        'game_round_id' => 'integer',
        'seq' => 'integer',
        'member_id' => 'integer',
    ];
}
