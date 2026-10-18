<?php
namespace App\Dao\Member;
use App\Dao\BaseDao;
use App\Model\Member\Member;

class MemberDao extends BaseDao
{

    protected function setModel(): string
    {
        return Member::class;
    }
}