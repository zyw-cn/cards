<?php

declare(strict_types=1);

namespace App\Service\Member;

use App\Dao\Member\FinancialLogDao;
use App\Dao\Member\MemberDao;
use App\Exception\ApiException;
use App\Service\BaseService;

/**
 * 资金服务：统一处理金币余额变动 + 流水记录
 *
 * 余额字段为 member.points（金币），金币与充值金额 1:1。
 */
class FinanceService extends BaseService
{
    /** 流水类型：充值 */
    public const FLOW_RECHARGE = 1;

    /** 流水类型：提现 */
    public const FLOW_WITHDRAW = 2;

    /** 流水类型：购买房卡（金币兑换） */
    public const FLOW_BUY_CARD = 3;

    /** 流水类型：游戏结算-赢 */
    public const FLOW_GAME_WIN = 4;

    /** 流水类型：游戏结算-输 */
    public const FLOW_GAME_LOSE = 5;

    /** 流水类型：支付平台费用（本局打完后按 pay_mode 扣除） */
    public const FLOW_ROOM_FEE = 6;

    /** 流水类型：中途解散按剩余局数折算返还（金币） */
    public const FLOW_ROOM_CARD_REMAIN_REFUND = 7;

    /** 流水类型文案 */
    public const FLOW_TYPE_LABELS = [
        self::FLOW_RECHARGE => '充值',
        self::FLOW_WITHDRAW => '提现',
        self::FLOW_BUY_CARD => '购买房卡',
        self::FLOW_GAME_WIN => '游戏获胜',
        self::FLOW_GAME_LOSE => '游戏结算',
        self::FLOW_ROOM_FEE => '平台费用',
        self::FLOW_ROOM_CARD_REMAIN_REFUND => '中途解散返还',
    ];

    /** 金额精度（member.points 为 decimal(12,2)） */
    private const SCALE = 2;

    public function __construct(
        MemberDao $dao,
        protected FinancialLogDao $logDao,
    ) {
        $this->dao = $dao;
    }

    /**
     * 通用资金变动：扣减/增加金币并写入流水。
     *
     * 全程在事务内执行，并对会员行加排它锁，避免并发超扣。
     *
     * @param int $memberId 会员ID
     * @param string $changeAmount 变动金额：正数=入账（充值），负数=出账（提现等）
     * @param int $flowType 流水类型，见 FLOW_* 常量
     * @param string $orderSn 关联业务单号
     * @param string $remark 备注
     * @return array{before: string, after: string, change: string, log_id: int}
     */
    public function change(
        int $memberId,
        string $changeAmount,
        int $flowType,
        string $orderSn = '',
        string $remark = '',
    ): array {
        if (bccomp($changeAmount, '0', self::SCALE) === 0) {
            throw new ApiException('变动金额不能为 0');
        }

        return $this->transaction(function () use ($memberId, $changeAmount, $flowType, $orderSn, $remark) {
            $member = $this->dao->lockForUpdate($memberId, ['id', 'points']);
            if (! $member) {
                throw new ApiException('用户不存在');
            }

            $before = (string) ($member->points ?? '0');
            $after = bcadd($before, $changeAmount, self::SCALE);

            if (bccomp($after, '0', self::SCALE) < 0) {
                throw new ApiException('金币余额不足');
            }

            $member->points = $after;
            $member->save();

            $logId = $this->logDao->create([
                'member_id' => $memberId,
                'change_amount' => $changeAmount,
                'before_amount' => $before,
                'after_amount' => $after,
                'flow_type' => $flowType,
                'order_sn' => $orderSn,
                'remark' => $remark,
                'before_frozen_amount' => '0.000',
                'after_froze_amount' => '0.000',
                'create_at' => date('Y-m-d H:i:s'),
            ]);

            return [
                'before' => $before,
                'after' => $after,
                'change' => $changeAmount,
                'log_id' => $logId,
            ];
        });
    }

    /**
     * 入账（增加金币），changeAmount 传正数。
     */
    public function income(int $memberId, string $amount, int $flowType, string $orderSn = '', string $remark = ''): array
    {
        $amount = $this->normalize($amount);
        if (bccomp($amount, '0', self::SCALE) <= 0) {
            throw new ApiException('入账金额必须大于 0');
        }

        return $this->change($memberId, $amount, $flowType, $orderSn, $remark);
    }

    /**
     * 出账（扣减金币），amount 传正数，内部转为负向变动。
     */
    public function expense(int $memberId, string $amount, int $flowType, string $orderSn = '', string $remark = ''): array
    {
        $amount = $this->normalize($amount);
        if (bccomp($amount, '0', self::SCALE) <= 0) {
            throw new ApiException('扣减金额必须大于 0');
        }

        return $this->change($memberId, '-' . $amount, $flowType, $orderSn, $remark);
    }

    /**
     * 会员资金流水列表（分页，可按流水类型筛选）。
     *
     * @param int $memberId 会员ID
     * @param int $flowType 流水类型，0=全部，其余见 FLOW_* 常量
     * @param int $page 页码
     * @param int $pageSize 每页数量
     */
    public function flowList(int $memberId, int $flowType = 0, int $page = 1, int $pageSize = 10): array
    {
        $page = max(1, $page);
        $pageSize = max(1, $pageSize);
        $offset = ($page - 1) * $pageSize;

        $where = ['member_id' => $memberId];
        if ($flowType !== 0) {
            $where['flow_type'] = $flowType;
        }

        $total = $this->logDao->count($where, false);

        $rows = $this->logDao->selectList(
            $where,
            '*',
            $offset,
            $pageSize,
            ['id' => 'desc']
        );

        return [
            'list' => array_map(fn ($row) => $this->formatLog($row), $rows->all()),
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
        ];
    }

    /**
     * 格式化单条流水
     */
    protected function formatLog($row): array
    {
        if (! $row) {
            return [];
        }

        $flowType = (int) $row->flow_type;

        return [
            'id' => (int) $row->id,
            'order_sn' => (string) $row->order_sn,
            'flow_type' => $flowType,
            'flow_type_text' => self::FLOW_TYPE_LABELS[$flowType] ?? '其他',
            'change_amount' => (float) $row->change_amount,
            'before_amount' => (float) $row->before_amount,
            'after_amount' => (float) $row->after_amount,
            'remark' => (string) $row->remark,
            'createTime' => strtotime((string) $row->create_at) * 1000,
        ];
    }

    /**
     * 生成业务流水单号：前缀 + 时间 + 会员ID + 随机数
     *
     * @param int $memberId 会员ID
     * @param string $prefix 业务前缀，如 R=充值 W=提现
     */
    public function buildOrderSn(int $memberId, string $prefix = 'F'): string
    {
        return $prefix . date('YmdHis') . $memberId . random_int(1000, 9999);
    }

    /**
     * 统一金额精度
     */
    private function normalize(string $amount): string
    {
        return bcadd($amount, '0', self::SCALE);
    }
}
