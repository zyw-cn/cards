<?php

declare(strict_types=1);

namespace App\Model\Game;

use App\Model\Model;

/**
 * 斗牛闲家下注记录表 h_niu_bet
 *
 * 约定：
 * - 仅闲家有行；庄家不写入
 * - 每人每局唯一一行（uk: game_round_id + member_id）
 * - multiple：相对底分的下注倍数（见 NiuConsts::BET_MULTIPLES）
 * - amount：落库时「底分 × multiple」快照，结算直接用 amount，避免事后改房间配置对不上账
 * - is_auto：超时代操作（默认最小倍数）
 *
 * @property int $id
 * @property int $game_round_id
 * @property int $seat_no
 * @property int $member_id
 * @property int $multiple
 * @property string $amount
 * @property int $is_auto
 * @property string $create_time
 */
class NiuBet extends Model
{
    protected ?string $table = 'niu_bet';

    public bool $timestamps = false;

    protected array $fillable = [
        'game_round_id',
        'seat_no',
        'member_id',
        'multiple',
        'amount',
        'is_auto',
        'create_time',
    ];

    protected array $casts = [
        'id' => 'integer',
        'game_round_id' => 'integer',
        'seat_no' => 'integer',
        'member_id' => 'integer',
        'multiple' => 'integer',
        'is_auto' => 'integer',
    ];
}
