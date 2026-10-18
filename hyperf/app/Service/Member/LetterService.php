<?php

declare(strict_types=1);

namespace App\Service\Member;

use App\Dao\Member\LetterDao;
use App\Exception\ApiException;
use App\Model\Member\Letter;
use App\Service\BaseService;

/**
 * 大厅站内信：全员(member_id=0) / 单发(member_id=uid)
 * scope: public=全员 | private=私信 | all=两者
 * 前台仅返回标题，无正文/时间/删除/已读
 */
class LetterService extends BaseService
{
    public const SCOPE_PUBLIC = 'public';

    public const SCOPE_PRIVATE = 'private';

    public const SCOPE_ALL = 'all';

    public function __construct(LetterDao $letterDao)
    {
        $this->dao = $letterDao;
    }

    /**
     * @return array{list: list<array<string,mixed>>, total: int, page: int, pageSize: int, scope: string}
     */
    public function listForMember(int $memberId, int $page = 1, int $pageSize = 20, string $scope = self::SCOPE_ALL): array
    {
        if ($memberId <= 0) {
            throw new ApiException('请先登录');
        }
        $page = max(1, $page);
        $pageSize = max(1, min(50, $pageSize));
        $offset = ($page - 1) * $pageSize;
        $scope = $this->normalizeScope($scope);

        $query = Letter::query()->where('is_del', 0);
        if ($scope === self::SCOPE_PUBLIC) {
            $query->where('member_id', Letter::SCOPE_ALL);
        } elseif ($scope === self::SCOPE_PRIVATE) {
            $query->where('member_id', $memberId);
        } else {
            $query->where(function ($q) use ($memberId) {
                $q->where('member_id', Letter::SCOPE_ALL)
                    ->orWhere('member_id', $memberId);
            });
        }

        $total = (clone $query)->count();
        $rows = $query
            ->orderByDesc('id')
            ->offset($offset)
            ->limit($pageSize)
            ->get(['id', 'title']);

        $list = [];
        foreach ($rows as $row) {
            $list[] = [
                'id'    => (int) $row->id,
                'title' => (string) $row->title,
            ];
        }

        return [
            'list'     => $list,
            'total'    => $total,
            'page'     => $page,
            'pageSize' => $pageSize,
            'scope'    => $scope,
        ];
    }

    private function normalizeScope(string $scope): string
    {
        $scope = strtolower(trim($scope));
        if ($scope === self::SCOPE_PUBLIC || $scope === self::SCOPE_PRIVATE) {
            return $scope;
        }

        return self::SCOPE_ALL;
    }

    /**
     * 业务写入：单发或全员（$memberId=0 为全员）
     */
    public function create(int $memberId, string $title, string $content, int $type = Letter::TYPE_SYSTEM, string $thumb = '', string $operator = 'system'): int
    {
        $title = trim($title);
        $content = trim($content);
        if ($title === '' || $content === '') {
            throw new ApiException('标题和内容不能为空');
        }
        $now = date('Y-m-d H:i:s');

        return (int) $this->dao->save([
            'member_id'       => max(0, $memberId),
            'title'           => mb_substr($title, 0, 255),
            'content'         => $content,
            'thumb'           => mb_substr($thumb, 0, 255),
            'type'            => $type === Letter::TYPE_BIZ ? Letter::TYPE_BIZ : Letter::TYPE_SYSTEM,
            'read_status'     => 0,
            'is_del'          => 0,
            'create_operator' => mb_substr($operator, 0, 31),
            'update_operator' => mb_substr($operator, 0, 31),
            'create_at'       => $now,
            'update_at'       => $now,
        ]);
    }
}
