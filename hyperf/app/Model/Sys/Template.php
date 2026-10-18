<?php
declare(strict_types=1);

namespace App\Model\Sys;



use App\Model\Model;

class Template extends Model
{
    protected ?string $table = 'template';

    public const StatusActive = 1;

    public const StatusInactive = 0;
}