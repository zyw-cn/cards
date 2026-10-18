<?php

declare(strict_types=1);

namespace App\Model\Member;

use App\Model\Model;
use HyperfExtension\Jwt\Contracts\JwtSubjectInterface;

/**
 * @property int $id
 * @property string $email
 * @property string $passwd
 * @property string $register_at
 * @property string $login_at
 * @property int $status
 * @property string $login_ip
 * @property string $login_address
 * @property string $points
 * @property int $is_agent
 * @property int $pid
 * @property string $tree
 * @property int $level
 * @property string $avatar
 * @property string $invite_code
 * @property string $update_at
 * @property string $realname
 * @property string $idcard
 * @property int $audit_status
 * @property string $trade_passwd
 * @property int $contract_switch
 * @property int $withdraw_status
 * @property string $customer_link
 */
class Member extends Model implements JwtSubjectInterface
{
    protected ?string $table = 'member';

    public bool $timestamps = false;

    protected array $hidden = ['passwd', 'trade_passwd'];

    protected array $fillable = [
        'email', 'passwd', 'register_at', 'login_at', 'status',
        'login_ip', 'login_address','nickname'
    ];

    protected array $casts = [
        'id' => 'integer',
        'status' => 'integer',
        'is_agent' => 'integer',
        'pid' => 'integer',
        'level' => 'integer',
        'audit_status' => 'integer',
        'contract_switch' => 'integer',
        'withdraw_status' => 'integer',
    ];

    public function getJwtIdentifier()
    {
        return $this->getKey();
    }

    public function getJwtCustomClaims(): array
    {
        return [];
    }

    public function scopeId($query,$value)
    {

        if ($value) {
            return $query->whereIn('id', $value);
        }
    }
}
