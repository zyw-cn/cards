<?php

declare(strict_types=1);

namespace App\Model\Game;

use App\Model\Model;

/**
 * 斗牛抢庄记录表 h_niu_grab
 *
 * 约定：
 * - 每人每局唯一一行（uk: game_round_id + member_id）
 * - multiple：0=不抢，1~4=抢庄倍数
 * - source：1 玩家主动；2 系统明庄（全员不抢时由 GrabBankerAction 回写）
 * - is_auto：超时代操作（Timeout 默认不抢）
 *
 * @property int $id
 * @property int $game_round_id
 * @property int $seat_no
 * @property int $member_id
 * @property int $multiple
 * @property int $source
 * @property int $is_auto
 * @property string $create_time
 */
class NiuGrab extends Model
{
    /** 玩家主动抢/不抢 */
    public const SOURCE_PLAYER = 1;

    /** 系统随机明庄 */
    public const SOURCE_SYSTEM = 2;

    protected ?string $table = 'niu_grab';

    public bool $timestamps = false;

    protected array $fillable = [
        'game_round_id',
        'seat_no',
        'member_id',
        'multiple',
        'source',
        'is_auto',
        'create_time',
    ];

    protected array $casts = [
        'id' => 'integer',
        'game_round_id' => 'integer',
        'seat_no' => 'integer',
        'member_id' => 'integer',
        'multiple' => 'integer',
        'source' => 'integer',
        'is_auto' => 'integer',
    ];
}
