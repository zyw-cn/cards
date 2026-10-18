<?php

declare(strict_types=1);

namespace App\Model\Game;

use App\Model\Model;

/**
 * @property int $id
 * @property int $total_play_count
 * @property int $played_count
 * @property int $status 1已创建 2游戏中 3已结束
 * @property string $create_time
 * @property string $update_time
 * @property string|null $end_time
 * @property string $room_num
 * @property string|null $start_time
 * @property int $game_type
 * @property int $member_id
 * @property int $num 本房最大人数
 * @property string|null $rule 建房规则 JSON 字符串
 */
class GameRoom extends Model
{
    /** 已创建（等人 / 未正式开局） */
    public const STATUS_CREATED = 1;

    /** @deprecated 使用 STATUS_CREATED */
    public const STATUS_CREATING = self::STATUS_CREATED;

    /** 游戏中 */
    public const STATUS_PLAYING = 2;

    /** 已结束（打满局数 / 超时解散等） */
    public const STATUS_ENDED = 3;

    /** @deprecated 已并入 STATUS_ENDED，勿再写入 4 */
    public const STATUS_DISSOLVED = self::STATUS_ENDED;

    protected ?string $table = 'room';

    public bool $timestamps = false;

    /** 房间是否仍可加入 / 进行中（已创建或游戏中） */
    public static function isActiveStatus(int $status): bool
    {
        return $status === self::STATUS_CREATED || $status === self::STATUS_PLAYING;
    }

    /** 是否已结束（含历史解散状态 4） */
    public static function isEndedStatus(int $status): bool
    {
        return $status === self::STATUS_ENDED || $status === 4;
    }

    /** @return list<int> */
    public static function activeStatuses(): array
    {
        return [self::STATUS_CREATED, self::STATUS_PLAYING];
    }

    protected array $casts = [
        'id' => 'integer',
        'total_play_count' => 'integer',
        'played_count' => 'integer',
        'status' => 'integer',
        'game_type' => 'integer',
        'member_id' => 'integer',
        'num' => 'integer',
    ];
}
