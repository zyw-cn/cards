<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace App\Service;

use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Contract\RequestInterface;

/**
 * Class BaseService
 * @package App\Service
 *
 * @method mixed get($id, ?array $field = null, ?array $with = []) 获取一条数据
 * @method mixed getOne(array $where, ?string $field = '*', array $with = []) 获取一条数据（不走搜索器）
 * @method int batchUpdate(array $ids, array $data, ?string $key = null) 批量修改
 * @method float sum(array $where, string $field, bool $search = false) 求和
 * @method bool update($id, array $data, ?string $key = null) 修改数据
 * @method bool be($map, string $field = '') 查询一条数据是否存在
 * @method mixed value($where, ?string $field = null) 获取指定条件下的数据
 * @method int count(array $where = [], bool $search = true) 读取数据条数
 * @method int getCount(array $where = []) 获取某些条件总数（不走搜索器）
 * @method array getColumn(array $where, string $field, string $key = '') 获取某个字段数组（不走搜索器）
 * @method bool delete($id, ?string $key = null) 删除
 * @method int save(array $data) 保存数据
 * @method \Hyperf\Database\Model\Collection saveAll(array $data) 批量保存数据
 * @method \Hyperf\Database\Model\Collection selectList(array $where, string|array $field = '*', int $offset = 0, int $limit = 0, string|array $orderBy = '', array $with = [], bool $search = false) 获取列表
 * @method \Hyperf\Database\Model\Builder selectModel(array $where, string|array $field = '*', int $offset = 0, int $limit = 0, string|array $orderBy = '', array $with = [], bool $search = false) 获取查询模型（不执行查询）
 * @method array getAll(array $where = [], string|array $field = '*', string|array $orderBy = '') 获取所有数据
 * @method bool bcInc($key, string $incField, string $inc, int $acc = 2) 高精度加法
 * @method bool bcDec(array $where, string $decField, string $dec, int $acc = 2) 高精度减法
 * @method mixed getMax(array $where = [], string $field = '') 获取最大值
 * @method mixed getMin(array $where = [], string $field = '') 获取最小值
 * @method array getList(array $where = [], int $offset = 0, int $limit = 10, string $field = '*', string $order = '', array $with = [], bool $search = false) 获取列表（分页格式）
 */
abstract class BaseService
{
    /**
     * Dao实例（由子类注入）
     * @var object
     */
    protected $dao;

    /**
     * 请求对象
     */
    #[Inject]
    protected RequestInterface $request;

    /**
     * 魔术方法：将未定义的方法调用转发到Dao
     *
     * @param string $name 方法名
     * @param array $arguments 参数
     * @return mixed
     */
    public function __call(string $name, array $arguments)
    {
        if ($this->dao && method_exists($this->dao, $name)) {
            return call_user_func_array([$this->dao, $name], $arguments);
        }

        throw new \BadMethodCallException("Method {$name} does not exist in " . static::class);
    }

    /**
     * 获取排序参数
     *
     * @return array|string 返回排序数组或空字符串
     */
    public function getOrder()
    {
        $params = $this->request->all();

        if (empty($params['order'])) {
            return '';
        }

        if (empty($params['field'])) {
            return '';
        }

        return [$params['field'] => $params['order']];
    }



    /**
     * 获取偏移量和限制参数（用于新的分页方法）
     *
     * @return array 返回 [偏移量, 每页数量]
     */
    public function getOffsetAndLimit(): array
    {
        $params = $this->request->all();
        $page = (int)($params['page'] ?? 1);
        $limit = (int)($params['limit'] ?? 10);
        $offset = ($page - 1) * $limit;

        return [$offset, $limit];
    }


    public function transaction(callable $closure, bool $isTran = true)
    {
        if ($isTran) {
            return Db::transaction($closure);
        }

        return $closure();
    }
}
