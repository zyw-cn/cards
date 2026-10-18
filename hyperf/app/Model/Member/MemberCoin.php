<?php

declare(strict_types=1);

namespace App\Model\Member;



use App\Model\Model;

/**
 * @property int $id 
 * @property int $coin_id 
 * @property int $member_id 
 * @property string $account_amount 
 * @property string $frozen_amount 
 * @property string $create_at 
 * @property string $update_at 
 */
class MemberCoin extends Model
{
    /**
     * The table associated with the model.
     */
    protected ?string $table = 'member_coin';

    /**
     * The attributes that are mass assignable.
     */
    protected array $fillable = [];

    /**
     * The attributes that should be cast to native types.
     */
    protected array $casts = ['id' => 'integer', 'coin_id' => 'integer', 'member_id' => 'integer'];
}
