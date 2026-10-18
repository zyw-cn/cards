<?php

declare(strict_types=1);

namespace App\Model\Sys;



use App\Model\Model;

/**
 * @property int $id 
 * @property string $content 
 * @property string $title 
 * @property string $create_at 
 * @property string $update_at 
 * @property int $status 
 * @property string $thumb 
 * @property int $sort 
 * @property string $desc 
 * @property int $create_uid 
 * @property string $create_operator 
 * @property int $update_uid 
 * @property string $update_operator 
 */
class Article extends Model
{
    /**
     * The table associated with the model.
     */
    protected ?string $table = 'article';

    /**
     * The attributes that are mass assignable.
     */
    protected array $fillable = [];

    /**
     * The attributes that should be cast to native types.
     */
    protected array $casts = ['id' => 'integer', 'status' => 'integer', 'sort' => 'integer', 'create_uid' => 'integer', 'update_uid' => 'integer'];
    public function categories()
    {
        return $this->belongsToMany(Category::class);
    }
}
