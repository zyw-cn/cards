<?php

declare(strict_types=1);

namespace App\Service\Member;

use App\Dao\Member\FeedbackDao;
use App\Exception\ApiException;
use App\Model\Member\Feedback;
use App\Service\BaseService;

/**
 * 大厅反馈：玩家提交；系统答复后出现在「答复」页
 */
class FeedbackService extends BaseService
{
    private const MIN_LEN = 8;

    private const MAX_LEN = 200;

    public function __construct(FeedbackDao $feedbackDao)
    {
        $this->dao = $feedbackDao;
    }

    /**
     * @return array{id:int}
     */
    public function submit(int $memberId, string $content): array
    {
        if ($memberId <= 0) {
            throw new ApiException('请先登录');
        }
        $content = trim($content);
        $len = mb_strlen($content);
        if ($len < self::MIN_LEN) {
            throw new ApiException('请至少填写 8 个字');
        }
        if ($len > self::MAX_LEN) {
            throw new ApiException('反馈内容不能超过 200 字');
        }
        $now = date('Y-m-d H:i:s');
        $id = (int) $this->dao->save([
            'member_id'   => $memberId,
            'content'     => $content,
            'reply'       => null,
            'status'      => Feedback::STATUS_PENDING,
            'read_status' => Feedback::UNREAD,
            'is_del'      => 0,
            'reply_at'    => null,
            'reply_by'    => '',
            'create_at'   => $now,
            'update_at'   => $now,
        ]);

        return ['id' => $id];
    }

    /**
     * 我的反馈列表（含答复）
     *
     * @return array{list: list<array<string,mixed>>, unread: int, total: int}
     */
    public function listForMember(int $memberId, int $page = 1, int $pageSize = 30): array
    {
        if ($memberId <= 0) {
            throw new ApiException('请先登录');
        }
        $page = max(1, $page);
        $pageSize = max(1, min(50, $pageSize));
        $offset = ($page - 1) * $pageSize;

        $where = ['member_id' => $memberId, 'is_del' => 0];
        $total = $this->dao->getCount($where);
        $rows = $this->dao->selectList(
            $where,
            'id,content,reply,status,read_status,reply_at,create_at',
            $offset,
            $pageSize,
            ['id' => 'desc']
        );

        $list = [];
        foreach ($rows as $row) {
            $arr = method_exists($row, 'toArray') ? $row->toArray() : (array) $row;
            $hasReply = (int) ($arr['status'] ?? 0) === Feedback::STATUS_REPLIED
                && trim((string) ($arr['reply'] ?? '')) !== '';
            $list[] = [
                'id'         => (int) ($arr['id'] ?? 0),
                'content'    => (string) ($arr['content'] ?? ''),
                'reply'      => $hasReply ? (string) $arr['reply'] : '',
                'status'     => (int) ($arr['status'] ?? 0),
                'hasReply'   => $hasReply,
                'readStatus' => (int) ($arr['read_status'] ?? 0),
                'replyAt'    => (string) ($arr['reply_at'] ?? ''),
                'createAt'   => (string) ($arr['create_at'] ?? ''),
            ];
        }

        return [
            'list'     => $list,
            'unread'   => $this->unreadReplyCount($memberId),
            'total'    => $total,
            'page'     => $page,
            'pageSize' => $pageSize,
        ];
    }

    /** 仅已答复且未读的条数（答复页角标） */
    public function unreadReplyCount(int $memberId): int
    {
        if ($memberId <= 0) {
            return 0;
        }

        return $this->dao->getCount([
            'member_id'   => $memberId,
            'is_del'      => 0,
            'status'      => Feedback::STATUS_REPLIED,
            'read_status' => Feedback::UNREAD,
        ]);
    }

    /**
     * 玩家标记某条答复已读
     *
     * @return array{id:int,readStatus:int,unread:int}
     */
    public function markReplyRead(int $memberId, int $id): array
    {
        $row = $this->assertOwned($memberId, $id);
        if ((int) $row->status !== Feedback::STATUS_REPLIED) {
            throw new ApiException('该反馈尚未答复');
        }
        if ((int) $row->read_status !== Feedback::READ) {
            $this->dao->update((int) $row->id, [
                'read_status' => Feedback::READ,
                'update_at'   => date('Y-m-d H:i:s'),
            ]);
        }

        return [
            'id'         => (int) $row->id,
            'readStatus' => Feedback::READ,
            'unread'     => $this->unreadReplyCount($memberId),
        ];
    }

    /**
     * 系统/客服答复（内部调用；暂无后台时可用于脚本/接口）
     *
     * @return array{id:int}
     */
    public function reply(int $id, string $reply, string $operator = 'system'): array
    {
        $reply = trim($reply);
        if ($reply === '') {
            throw new ApiException('答复内容不能为空');
        }
        if (mb_strlen($reply) > 1000) {
            throw new ApiException('答复过长');
        }
        /** @var Feedback|null $row */
        $row = $this->dao->getOne(['id' => $id, 'is_del' => 0]);
        if ($row === null) {
            throw new ApiException('反馈不存在');
        }
        $now = date('Y-m-d H:i:s');
        $this->dao->update((int) $row->id, [
            'reply'       => $reply,
            'status'      => Feedback::STATUS_REPLIED,
            'read_status' => Feedback::UNREAD,
            'reply_at'    => $now,
            'reply_by'    => mb_substr($operator, 0, 31),
            'update_at'   => $now,
        ]);

        return ['id' => (int) $row->id];
    }

    private function assertOwned(int $memberId, int $id): Feedback
    {
        if ($memberId <= 0 || $id <= 0) {
            throw new ApiException('参数错误');
        }
        /** @var Feedback|null $row */
        $row = $this->dao->getOne(['id' => $id, 'member_id' => $memberId, 'is_del' => 0]);
        if ($row === null) {
            throw new ApiException('反馈不存在');
        }

        return $row;
    }
}
