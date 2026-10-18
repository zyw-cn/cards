<?php

declare(strict_types=1);

namespace App\Model\Sys;



use App\Model\Model;

/**
 * @property int $id 
 * @property string $name 
 * @property int $type 
 * @property string $title 
 * @property int $group 
 * @property string $extra 
 * @property string $remark 
 * @property int $create_at 
 * @property int $update_at 
 * @property int $status 
 * @property string $value 
 * @property int $sort 
 * @property int $create_uid 
 * @property string $create_operator 
 * @property int $update_uid 
 * @property string $update_operator 
 */
class Setting extends Model
{
    /**
     * The table associated with the model.
     */
    protected ?string $table = 'setting';

    /**
     * The attributes that are mass assignable.
     */
    protected array $fillable = [];

    /**
     * The attributes that should be cast to native types.
     */
    protected array $casts = ['id' => 'integer', 'type' => 'integer', 'group' => 'integer', 'create_at' => 'integer', 'update_at' => 'integer', 'status' => 'integer', 'sort' => 'integer', 'create_uid' => 'integer', 'update_uid' => 'integer'];


    public function scopeStatus($query,$value)
    {
        if ($value) {
            return $query->where('status', 1);
        }
    }
}
