<?php

declare(strict_types=1);

namespace App\Model\Member;

use App\Model\Model;

/**
 * 大厅玩家反馈
 *
 * @property int $id
 * @property int $member_id
 * @property string $content
 * @property string|null $reply
 * @property int $status
 * @property int $read_status
 * @property int $is_del
 * @property string|null $reply_at
 * @property string $reply_by
 * @property string $create_at
 * @property string $update_at
 */
class Feedback extends Model
{
    protected ?string $table = 'feedback';

    public bool $timestamps = false;

    protected array $fillable = [
        'member_id',
        'content',
        'reply',
        'status',
        'read_status',
        'is_del',
        'reply_at',
        'reply_by',
        'create_at',
        'update_at',
    ];

    protected array $casts = [
        'id' => 'integer',
        'member_id' => 'integer',
        'status' => 'integer',
        'read_status' => 'integer',
        'is_del' => 'integer',
    ];

    public const STATUS_PENDING = 0;

    public const STATUS_REPLIED = 1;

    public const UNREAD = 0;

    public const READ = 1;
}
