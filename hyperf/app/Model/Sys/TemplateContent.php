<?php

declare(strict_types=1);

namespace App\Model\Sys;

use App\Model\Model;

class TemplateContent extends Model
{
    protected ?string $table = 'template_content';

    public bool $timestamps = false;

    public const Success = 1;

    public const Failed = -1;

    protected array $fillable = [
        'template_id',
        'email',
        'content',
        'ip',
        'user_agent',
        'city',
        'is_success',
        'fail_reason',
    ];

    protected array $casts = [
        'id' => 'integer',
        'template_id' => 'integer',
        'is_success' => 'integer',
    ];
}
