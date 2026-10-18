<?php

declare(strict_types=1);

namespace App\Model\Member;

use App\Model\Model;

/**
 * 站内信正文
 *
 * @property int $id
 * @property string|null $content
 * @property int $read_status
 * @property string $title
 * @property string|null $create_at
 * @property string|null $update_at
 * @property string $create_operator
 * @property string $update_operator
 * @property int $member_id 0=全员；>0=仅该玩家
 * @property int $is_del
 * @property string $thumb
 * @property int $type 1系统 2业务
 */
class Letter extends Model
{
    protected ?string $table = 'letter';

    public bool $timestamps = false;

    protected array $fillable = [
        'content',
        'read_status',
        'title',
        'create_at',
        'update_at',
        'create_operator',
        'update_operator',
        'member_id',
        'is_del',
        'thumb',
        'type',
    ];

    protected array $casts = [
        'id' => 'integer',
        'read_status' => 'integer',
        'member_id' => 'integer',
        'is_del' => 'integer',
        'type' => 'integer',
    ];

    public const SCOPE_ALL = 0;

    public const TYPE_SYSTEM = 1;

    public const TYPE_BIZ = 2;
}
