<?php
namespace App\Dao\Sys;
use App\Dao\BaseDao;
use App\Model\Sys\Article;


class ArticleDao extends BaseDao
{
    protected function setModel(): string
    {
        return Article::class;
    }

    public function getList(array $where, string $field = '*', int $page = 0, int $limit = 0, mixed $order = [])
    {
        $order = $order ?: [$this->getPk()=>"desc"];
        $offset = ($page - 1) * $limit;
        return $this->search($where)->select($field)->with(["categories"=>function ($query) {
            $query->select("category.id as category_id","name");
        }])->offset($offset)->limit($limit)->orderBy($order)->get()->toArray();

    }
}