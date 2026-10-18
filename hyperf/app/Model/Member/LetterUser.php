<?php

declare(strict_types=1);

namespace App\Model\Member;

use App\Model\Model;

/**
 * 站内信每人已读 / 玩家侧删除
 *
 * @property int $id
 * @property int $letter_id
 * @property int $member_id
 * @property int $read_status
 * @property int $is_del
 * @property string|null $read_at
 * @property string $create_at
 */
class LetterUser extends Model
{
    protected ?string $table = 'letter_user';

    public bool $timestamps = false;

    protected array $fillable = [
        'letter_id',
        'member_id',
        'read_status',
        'is_del',
        'read_at',
        'create_at',
    ];

    protected array $casts = [
        'id' => 'integer',
        'letter_id' => 'integer',
        'member_id' => 'integer',
        'read_status' => 'integer',
        'is_del' => 'integer',
    ];

    public const UNREAD = 0;

    public const READ = 1;
}
