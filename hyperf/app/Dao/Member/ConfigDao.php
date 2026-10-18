<?php
namespace App\Dao\Member;
use App\Dao\BaseDao;
use App\Model\Sys\Config;

class ConfigDao extends BaseDao
{

    protected function setModel(): string
    {
        return Config::class;
    }

    public function getList(array $where, string $field = '*', int $page = 0, int $limit = 0, mixed $order = [])
    {
        $order = $order ?: [$this->getPk()=>"desc"];
        $offset = ($page - 1) * $limit;
        return $this->search($where)->select($field)->offset($offset)->limit($limit)->orderBy($order)->get()->toArray();
    }
}