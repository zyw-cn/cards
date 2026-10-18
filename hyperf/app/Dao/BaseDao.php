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

namespace App\Dao;

use App\Model\Model;
use Hyperf\Database\Model\Builder;
use Hyperf\Database\Model\Collection;
use Hyperf\Stringable\Str;
use ReflectionClass;
use function Hyperf\Support\make;

/**
 * Class BaseDao
 * @package App\Dao
 */
abstract class BaseDao
{
    /**
     * 当前表名别名
     * @var string|null
     */
    protected ?string $alias = null;

    /**
     * join表别名
     * @var string|null
     */
    protected ?string $joinAlias = null;

    /**
     * 模型实例缓存
     * @var Model|null
     */
    private ?Model $modelCache = null;

    /**
     * 主键缓存
     * @var string|array|null
     */
    private $pkCache = null;

    /**
     * 获取当前模型类名
     * @return string
     */
    abstract protected function setModel(): string;

    /**
     * 设置join链表模型
     * @return string
     */
    protected function setJoinModel(): string
    {
        return '';
    }

    /**
     * 读取数据条数
     * @param array $where 查询条件
     * @param bool $search 是否使用搜索器
     * @return int
     * @throws \ReflectionException
     */
    public function count(array $where = [], bool $search = true): int
    {
        return $this->search($where, $search)->count();
    }

    /**
     * 获取某些条件数据
     * @param array $where 查询条件
     * @param string|array $field 查询字段
     * @param int $offset 偏移量
     * @param int $limit 每页数量
     * @param array $orderBy 排序，支持一维关联数组如 ["id" => "desc"] 或 ["id" => "desc", "created_at" => "asc"]
     * @param array $with 关联查询
     * @param bool $search 是否使用搜索器
     * @return Collection
     * @throws \ReflectionException
     */
    public function selectList(array $where, string|array $field = '*', int $offset = 0, int $limit = 0, array $orderBy = [], array $with = [], bool $search = false)
    {
        return $this->selectModel($where, $field, $offset, $limit, $orderBy, $with, $search)->get();
    }

    /**
     * 获取查询模型（不执行查询）
     * @param array $where 查询条件
     * @param string|array $field 查询字段
     * @param int $offset 偏移量
     * @param int $limit 每页数量
     * @param array $orderBy 排序，支持一维关联数组如 ["id" => "desc"] 或 ["id" => "desc", "created_at" => "asc"]
     * @param array $with 关联查询
     * @param bool $search 是否使用搜索器
     * @return Builder
     * @throws \ReflectionException
     */
    public function selectModel(array $where, string|array $field = '*', int $offset = 0, int $limit = 0, array $orderBy = [], array $with = [], bool $search = false)
    {
        $model = $search ? $this->search($where) : $this->getModel()::query()->where($where);

        $query = $model->when($field !== '*', function ($query) use ($field) {
            $fields = is_array($field) ? $field : explode(',', $field);
            $query->select($fields);
        })->when($offset >= 0 && $limit > 0, function ($query) use ($offset, $limit) {
            $query->offset($offset)->limit($limit);
        })->when($with, function ($query) use ($with) {
            $query->with($with);
        })->when($orderBy, function ($query) use ($orderBy) {
            // 一维关联数组格式：["id" => "desc"] 或 ["id" => "desc", "created_at" => "asc"]
            foreach ($orderBy as $field => $direction) {
                $direction = strtolower($direction ?: 'desc');
                if (in_array($direction, ['asc', 'desc'], true)) {
                    $query->orderBy($field, $direction);
                }
            }
        });
        return $query;
    }



    /**
     * 获取某些条件总数
     * @param array $where 查询条件
     * @return int
     */
    public function getCount(array $where): int
    {
        return $this->getModel()::query()->where($where)->count();
    }

    /**
     * 获取某些条件去重总数
     * @param array $where 查询条件
     * @param string $field 字段名
     * @param bool $search 是否使用搜索器
     * @return int
     * @throws \ReflectionException
     */
    public function getDistinctCount(array $where, string $field, bool $search = true): int
    {
        $model = $search ? $this->search($where) : $this->getModel()::query()->where($where);
        $result = $model->selectRaw('COUNT(DISTINCT ' . $field . ') as count')->first();

        return (int)($result->count ?? 0);
    }

    /**
     * 获取模型实例
     * @return Model
     */
    protected function getModel(): Model
    {
        if ($this->modelCache === null) {
            $this->modelCache = make($this->setModel());
        }
        return $this->modelCache;
    }

    /**
     * 获取主键
     * @return string|array
     */
    protected function getPk()
    {
        if ($this->pkCache === null) {
            $model = $this->getModel();
            $this->pkCache = $model->getKeyName();
        }
        return $this->pkCache;
    }

    /**
     * 获取表名
     * @return string
     */
    public function getTableName(): string
    {
        return $this->getModel()->getTable();
    }

    /**
     * 获取一条数据
     * @param int|string|array $id 主键ID或查询条件
     * @param array|null $field 查询字段
     * @param array|null $with 关联查询
     * @param array|null $orderBy 排序
     * @return Model|null
     */
    public function get($id, ?array $field = null, ?array $with = [],$orderBy = [])
    {
        $where = is_array($id) ? $id : [$this->getPk() => $id];

        $query = $this->getModel()::query()->where($where);

        if (!empty($with)) {
            $query->with($with);
        }

        if ($field !== null) {
            $query->select($field);
        }

        if (!empty($orderBy)) {
            $query->orderBy($orderBy);
        }
        return $query->first();
    }

    /**
     * 加排它锁获取一条数据（必须在事务中使用，否则锁不生效）
     * @param int|string|array $id 主键ID或查询条件
     * @param array|null $field 查询字段
     * @return Model|null
     */
    public function lockForUpdate($id, ?array $field = null)
    {
        $where = is_array($id) ? $id : [$this->getPk() => $id];

        $query = $this->getModel()::query()->where($where)->lockForUpdate();

        if ($field !== null) {
            $query->select($field);
        }

        return $query->first();
    }

    /**
     * 查询一条数据是否存在
     * @param int|string|array $map 查询条件或主键值
     * @param string $field 字段名（当$map不是数组时使用）
     * @return bool 是否存在
     */
    public function be($map, string $field = ''): bool
    {
        if (!is_array($map)) {
            $field = $field ?: $this->getPk();
            $map = [$field => $map];
        }
        return $this->getModel()::query()->where($map)->count() > 0;
    }

    /**
     * 根据条件获取一条数据
     * @param array $where 查询条件
     * @param string|null $field 查询字段
     * @param array $with 关联查询
     * @return Model|null
     */
    public function getOne(array $where, ?string $field = '*', array $with = [])
    {
        $fields = $field === '*' ? ['*'] : explode(',', $field);
        return $this->get($where, $fields, $with);
    }

    /**
     * 获取单个字段值
     * @param int|string|array $where 查询条件或主键值
     * @param string|null $field 字段名
     * @return mixed
     * @throws \ReflectionException
     */
    public function value($where, ?string $field = null)
    {
        $field = $field ?: $this->getPk();
        return $this->search($this->setWhere($where))->value($field);
    }

    /**
     * 获取某个字段数组
     * @param array $where 查询条件
     * @param string $field 字段名
     * @param string $key 作为键的字段名
     * @return array
     */
    public function getColumn(array $where, string $field, string $key = ''): array
    {
        $query = $this->getModel()::query()->where($where);
        if ($key) {
            return $query->pluck($field, $key)->toArray();
        }
        return $query->pluck($field)->toArray();
    }

    /**
     * 删除数据
     * @param int|string|array $id 主键ID或查询条件
     * @param string|null $key 字段名（当$id不是数组时使用）
     * @return bool
     */
    public function delete($id, ?string $key = null): bool
    {
        $where = is_array($id) ? $id : [($key ?? $this->getPk()) => $id];
        return $this->getModel()::query()->where($where)->delete() > 0;
    }

    /**
     * 删除记录
     * @param int $id
     * @param bool $force
     * @return bool
     */
    public function destroy(int $id, bool $force = false): bool
    {
        $model = $this->getModel()::query()->find($id);
        if (!$model) {
            return false;
        }
        return $force ? $model->forceDelete() !== null : $model->delete() !== null;
    }

    /**
     * 更新数据
     * @param int|string|array $id 主键ID或查询条件
     * @param array $data 更新数据
     * @param string|null $key 字段名（当$id不是数组时使用）
     * @return bool
     */
    public function update($id, array $data, ?string $key = null): bool
    {
        $where = is_array($id) ? $id : [($key ?? $this->getPk()) => $id];
        return $this->getModel()::query()->where($where)->update($data) > 0;
    }

    /**
     * 设置查询条件
     * @param int|string|array $where 查询条件或主键值
     * @param string|null $key 字段名（当$where不是数组时使用）
     * @return array
     */
    protected function setWhere($where, ?string $key = null): array
    {
        if (!is_array($where)) {
            $where = [($key ?? $this->getPk()) => $where];
        }
        return $where;
    }

    /**
     * 批量更新数据
     * @param array $ids ID数组
     * @param array $data 更新数据
     * @param string|null $key 字段名
     * @return int 影响行数
     */
    public function batchUpdate(array $ids, array $data, ?string $key = null): int
    {
        if (empty($ids)) {
            return 0;
        }
        return $this->getModel()::query()->whereIn($key ?? $this->getPk(), $ids)->update($data);
    }

    /**
     * 插入单条数据
     * @param array $data 数据
     * @return int 返回插入的主键ID
     */
    public function create(array $data): int
    {
        return $this->getModel()::query()->insertGetId($data);
    }

    /**
     * 批量插入数据
     * @param array $data 数据数组
     * @return bool
     */
    public function saveAll(array $data): bool
    {
        if (empty($data)) {
            return false;
        }
        return (bool)$this->getModel()::insert($data);
    }

    /**
     * 获取某个字段内的值
     * @param mixed $value 值
     * @param string $field 字段名
     * @param string|null $valueKey 值字段名
     * @param array $where 额外查询条件
     * @return mixed
     */
    public function getFieldValue($value, string $field, ?string $valueKey = null, array $where = [])
    {
        $query = $this->getModel()::query()->where($field, $value);
        if (!empty($where)) {
            $query->where($where);
        }
        if ($valueKey) {
            return $query->value($valueKey);
        }
        return $query->first();
    }

    /**
     * 获取搜索器和搜索条件key,以及不在搜索器的条件数组
     * @param array $where 查询条件
     * @return array{0: array, 1: array} [搜索器字段数组, 其他条件数组]
     * @throws \ReflectionException
     */
    private function getSearchData(array $where): array
    {
        $with = [];
        $otherWhere = [];
        $reflection = new ReflectionClass($this->getModel());

        foreach ($where as $key => $value) {
            $key = (string)$key;
            $method = 'scope' . Str::studly($key);
            if ($reflection->hasMethod($method)) {
                $with[] = $key;
            } else {
                if (!is_array($value)) {
                    $otherWhere[] = [$key, '=', $value];
                } elseif (
                    count($value) === 3
                    && array_key_exists(0, $value)
                    && array_key_exists(1, $value)
                    && array_key_exists(2, $value)
                    && is_string($value[0])
                    && is_string($value[1])
                ) {
                    // 仅当形如 ['field', 'operator', value] 时才视作三元条件
                    $otherWhere[] = $value;
                } else {
                    // 约定：普通数组值按 whereIn 处理（如 ['id' => [1,2,3]]）
                    $otherWhere[] = [$key, 'in', $value];
                }
            }
        }

        return [$with, $otherWhere];
    }

    /**
     * 使用搜索器查询
     * @param array $where
     * @param bool $search
     * @return Builder
     * @throws \ReflectionException
     */
    protected function withSearchSelect(array $where, bool $search)
    {
        [$with, $otherWhere] = $this->getSearchData($where);
        $modelClass = $this->setModel();
        $query = $modelClass::query();

        // 应用搜索器
        foreach ($with as $scopeName) {
            $value = $where[$scopeName] ?? null;
            if ($value !== null) {
                $scopeMethod = 'scope' . Str::studly($scopeName);
                $reflection = new ReflectionClass($modelClass);
                if ($reflection->hasMethod($scopeMethod)) {
                    // 通过 Builder 调用 scope，避免把 Query Builder 当成 scope 参数
                    $query = $query->{$scopeName}($value);
                }
            }
        }

        // 应用其他条件
        if ($search && !empty($otherWhere)) {
            foreach ($this->filterWhere($otherWhere) as $condition) {
                $column = $condition[0] ?? null;
                $operator = strtolower((string) ($condition[1] ?? '='));
                $value = $condition[2] ?? null;

                if (!is_string($column) || $column === '') {
                    continue;
                }

                if ($operator === 'in' && is_array($value)) {
                    $query->whereIn($column, $value);
                    continue;
                }

                if ($operator === 'not in' && is_array($value)) {
                    $query->whereNotIn($column, $value);
                    continue;
                }

                if ($operator === 'null') {
                    $query->whereNull($column);
                    continue;
                }

                if ($operator === 'not null') {
                    $query->whereNotNull($column);
                    continue;
                }

                $query->where($column, $condition[1] ?? '=', $value);
            }
        }

        return $query;
    }

    /**
     * 过滤查询条件，只保留表中存在的字段
     * @param array $where 查询条件
     * @return array
     */
    protected function filterWhere(array $where = []): array
    {
        if (empty($where)) {
            return [];
        }

        $fields = $this->getTableFields();
        return array_filter($where, function ($item) use ($fields) {
            return isset($item[0]) && in_array($item[0], $fields, true);
        });
    }

    /**
     * 获取表字段列表
     * @return array
     */
    protected function getTableFields(): array
    {
        try {
            $table = $this->getTableName();
            $model = $this->getModel();
            $connection = $model->getConnection();
            return $connection->getSchemaBuilder()->getColumnListing($table);
        } catch (\Exception $e) {
            // 如果获取失败，返回空数组
            return [];
        }
    }

    /**
     * 搜索
     * @param array $where 查询条件
     * @param bool $search 是否应用其他条件
     * @return Builder
     * @throws \ReflectionException
     */
    public function search(array $where = [], bool $search = true)
    {
        if (empty($where)) {
            $modelClass = $this->getModel();
            return $modelClass::query();
        }
        return $this->withSearchSelect($where, $search);
    }

    /**
     * 求和
     * @param array $where 查询条件
     * @param string $field 字段名
     * @param bool $search 是否使用搜索器
     * @return float
     * @throws \ReflectionException
     */
    public function sum(array $where, string $field, bool $search = false): float
    {
        $model = $search ? $this->search($where) : $this->getModel()::query()->where($where);
        return (float)$model->sum($field);
    }

    /**
     * 高精度加法
     * @param array|int|string $key 主键值或查询条件数组
     * @param string $incField 要增加的字段
     * @param string $inc 增加的值
     * @param int $acc 精度
     * @return bool
     */
    public function bcInc($key, string $incField, string $inc, int $acc = 2): bool
    {
        // 将主键值转换为查询条件数组
        $where = is_array($key) ? $key : [$this->getPk() => $key];
        return $this->bc($where, $incField, $inc, 1, $acc);
    }

    /**
     * 高精度减法
     * @param array $where 查询条件
     * @param string $decField 要减少的字段
     * @param string $dec 减少的值
     * @param int $acc 精度
     * @return bool
     */
    public function bcDec(array $where, string $decField, string $dec, int $acc = 2): bool
    {
        return $this->bc($where, $decField, $dec, 2, $acc);
    }

    /**
     * 高精度计算并保存
     * @param array $where 查询条件
     * @param string $incField 要操作的字段
     * @param string $inc 操作的值
     * @param int $type 操作类型 1=加法 2=减法
     * @param int $acc 精度
     * @return bool
     */
    public function bc(array $where, string $incField, string $inc, int $type = 1, int $acc = 2): bool
    {
        $model = $this->getModel()::query()->where($where)->first();

        if (!$model) {
            return false;
        }

        $currentValue = (string)($model->{$incField} ?? '0');
        $newValue = '';
        if ($type === 1) {
            $newValue = bcadd($currentValue, $inc, $acc);
        } elseif ($type === 2) {
            if (bccomp($currentValue, $inc, $acc) < 0) {
                return false;
            }
            $newValue = bcsub($currentValue, $inc, $acc);
        } else {
            return false;
        }

        // 设置新值并保存
        $model->{$incField} = $newValue;
        return $model->save();
    }

    /**
     * 获取条件数据中的某个值的最大值
     * @param array $where 查询条件
     * @param string $field 字段名
     * @return mixed
     */
    public function getMax(array $where = [], string $field = '')
    {
        return $this->getModel()::query()->where($where)->max($field);
    }

    /**
     * 获取条件数据中的某个值的最小值
     * @param array $where 查询条件
     * @param string $field 字段名
     * @return mixed
     */
    public function getMin(array $where = [], string $field = '')
    {
        return $this->getModel()::query()->where($where)->min($field);
    }

    /**
     * 保存数据（新增或更新）
     * @param array $params 数据参数
     * @return int 返回主键ID
     */
    public function save(array $params = []): int
    {
        $pk = $this->getPk();
        $pkValue = $params[$pk] ?? null;
        $isUpdate = !empty($pkValue);

        // 获取表字段，用于过滤无效字段
        $fields = $this->getTableFields();
        $fieldsMap = array_flip($fields);

        // 过滤参数，只保留表中存在的字段
        $data = array_intersect_key($params, $fieldsMap);

        $modelClass = $this->getModel();
        if ($isUpdate) {
            // 更新模式：查找现有记录
            $model = $modelClass::query()->find($pkValue);
            if (!$model) {
                throw new \RuntimeException('record not found');
            }
            $model->fill($data);
            $model->save();
            return (int)$pkValue;
        } else {
            // 新增模式：创建新模型实例
            $model = $modelClass::create($data);
            return (int)$model->getKey();
        }
    }

    /**
     * 获取所有数据
     * @param array $where 查询条件
     * @param string|array $field 查询字段
     * @param array $orderBy 排序，支持一维关联数组如 ["id" => "desc"] 或 ["id" => "desc", "created_at" => "asc"]
     * @return array
     * @throws \ReflectionException
     */
    public function getAll(array $where = [], string|array $field = '*', array $orderBy = [])
    {
        $query = $this->search($where);

        // 应用字段选择
        if ($field !== '*') {
            $fields = is_array($field) ? $field : explode(',', $field);
            $query->select($fields);
        }

        // 应用排序
        if (!empty($orderBy)) {
            // 一维关联数组格式：["id" => "desc"] 或 ["id" => "desc", "created_at" => "asc"]
            foreach ($orderBy as $field => $direction) {
                $direction = strtolower($direction ?: 'desc');
                if (in_array($direction, ['asc', 'desc'], true)) {
                    $query->orderBy($field, $direction);
                }
            }
        } else {
            // 如果没有指定排序，使用主键降序
            $pk = $this->getPk();
            if (is_string($pk)) {
                $query->orderBy($pk, 'desc');
            }
        }

        return $query->get()->toArray();
    }

    /**
     * 批量清空数据
     * @return bool
     */
    public function clear(): bool
    {
        return $this->getModel()::query()->whereRaw('1=1')->delete() > 0;
    }

    /**
     * 原生 SQL 条件查询（返回查询构建器，支持链式调用）
     * @param string $where 原生 SQL 条件，支持占位符 ?，例如: 'price > IF(state = "TX", ?, 100)'
     * @param array $bind 绑定参数数组，例如: [200]
     * @param string $logic 逻辑连接符 'AND' 或 'OR'，默认 'AND'
     * @param Builder|null $query 可选的查询构建器，如果提供则在现有查询上添加条件
     * @return Builder
     */
    protected function getWhereRaw(string $where, array $bind = [], string $logic = 'AND', ?Builder $query = null): Builder
    {
        // 如果没有提供查询构建器，创建新的
        if ($query === null) {
            $query = $this->getModel()::query();
        }

        // 如果条件为空，直接返回查询构建器
        if (empty(trim($where))) {
            return $query;
        }

        // 规范化逻辑连接符
        $logicUpper = strtoupper(trim($logic));

        // 根据逻辑连接符选择方法
        if ($logicUpper === 'OR') {
            $query->orWhereRaw($where, $bind);
        } else {
            // 默认使用 AND
            $query->whereRaw($where, $bind);
        }

        return $query;
    }

    /**
     * 原生 SQL 条件查询并执行（返回数组）
     * @param string $where 原生 SQL 条件，支持占位符 ?
     * @param array $bind 绑定参数数组
     * @param string $logic 逻辑连接符 'AND' 或 'OR'，默认 'AND'
     * @param Builder|null $query 可选的查询构建器
     * @return array
     */
    protected function getWhereRawArray(string $where, array $bind = [], string $logic = 'AND', ?Builder $query = null): array
    {
        return $this->getWhereRaw($where, $bind, $logic, $query)->get()->toArray();
    }

}
