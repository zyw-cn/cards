<?php

declare(strict_types=1);

namespace App\Service\Member;

use App\Dao\Member\MemberDao;
use App\Dao\Member\WithdrawDao;
use App\Exception\ApiException;
use App\Service\BaseService;
use Hyperf\Contract\ConfigInterface;

class WithdrawService extends BaseService
{
    /** 金币与提现金额比例：1 元 = 1 金币（points 与金额 1:1） */
    public const COIN_PER_RMB = 1;

    /** 提现手续费率（10%） */
    public const WITHDRAW_FEE_RATE = '0.10';

    public const STATUS_PENDING = 1;

    public const STATUS_APPROVED = 2;

    public const STATUS_REJECTED = -1;

    public function __construct(
        protected WithdrawDao $withdrawDao,
        protected MemberDao $memberDao,
        protected FinanceService $financeService,
        protected ConfigInterface $config,
    ) {
        $this->dao = $withdrawDao;
    }

    public function payConfig(): array
    {
        $cfg = $this->config->get('finance.pay', []);

        return [
            'coin_per_rmb' => self::COIN_PER_RMB,
            'min_withdraw_rmb' => (int) ($cfg['min_withdraw_rmb'] ?? 100),
            'withdraw_fee_rate' => (float) self::WITHDRAW_FEE_RATE,
            'wechat' => [
                'name' => (string) ($cfg['wechat_name'] ?? '游戏官方收款（微信）'),
            ],
            'alipay' => [
                'name' => (string) ($cfg['alipay_name'] ?? '游戏官方收款（支付宝）'),
            ],
        ];
    }

    /**
     * 提交提现申请：校验 -> 扣减金币并写入流水（统一走 FinanceService） -> 生成提现订单。
     */
    public function submitWithdraw(
        int $memberId,
        float $rmb,
        string $channel,
        string $realName,
        string $payAccount,
    ): int {
        $pay = $this->payConfig();
        $minRmb = $pay['min_withdraw_rmb'];
        if ($rmb < $minRmb) {
            throw new ApiException(sprintf('最低提现 %d 元', $minRmb));
        }
        if (! in_array($channel, ['wechat', 'alipay'], true)) {
            throw new ApiException('请选择提现渠道');
        }
        $realName = trim($realName);
        $payAccount = trim($payAccount);
        if ($realName === '') {
            throw new ApiException('请输入收款人姓名');
        }
        if ($payAccount === '') {
            throw new ApiException('请输入收款账号');
        }

        $member = $this->memberDao->getOne(['id' => $memberId], 'id,withdraw_status');
        if (! $member) {
            throw new ApiException('用户不存在');
        }
        if ((int) $member->withdraw_status !== 1) {
            throw new ApiException('提现功能已关闭，请联系客服');
        }

        $amount = bcadd((string) $rmb, '0', 2);
        $coinsNeed = bcmul($amount, (string) self::COIN_PER_RMB, 2);
        $fee = bcmul($amount, self::WITHDRAW_FEE_RATE, 2);
        $receive = bcsub($amount, $fee, 2);
        $orderSn = $this->buildOrderSn($memberId);

        return $this->transaction(function () use (
            $memberId,
            $amount,
            $coinsNeed,
            $fee,
            $receive,
            $channel,
            $realName,
            $payAccount,
            $orderSn,
        ) {
            // 扣金币 + 写流水（余额不足会在此抛异常并回滚）
            $this->financeService->expense(
                $memberId,
                $coinsNeed,
                FinanceService::FLOW_WITHDRAW,
                $orderSn,
                sprintf('提现 %s 元（手续费 %s）', $amount, $fee),
            );

            return $this->withdrawDao->create([
                'num' => $amount,
                'receive_num' => $receive,
                'fee' => $fee,
                'member_id' => $memberId,
                'status' => self::STATUS_PENDING,
                'remark' => '',
                'address_id' => 0,
                'order_sn' => $orderSn,
                'channel' => $channel,
                'real_name' => $realName,
                'pay_account' => $payAccount,
            ]);
        });
    }

    public function withdrawList(int $memberId, int $page = 1, int $pageSize = 10): array
    {
        $page = max(1, $page);
        $pageSize = max(1, $pageSize);
        $offset = ($page - 1) * $pageSize;

        $where = ['member_id' => $memberId];

        $total = $this->withdrawDao->count($where, false);

        $rows = $this->withdrawDao->selectList(
            $where,
            '*',
            $offset,
            $pageSize,
            ['id' => 'desc']
        );

        return [
            'list' => array_map(fn ($row) => $this->formatRow($row), $rows->all()),
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
        ];
    }

    /** 生成提现流水单号：W + 时间 + 会员ID + 随机数 */
    protected function buildOrderSn(int $memberId): string
    {
        return 'W' . date('YmdHis') . $memberId . random_int(1000, 9999);
    }

    protected function formatRow($row): array
    {
        if (! $row) {
            return [];
        }

        $statusMap = [
            self::STATUS_PENDING => 'pending',
            self::STATUS_APPROVED => 'approved',
            self::STATUS_REJECTED => 'rejected',
        ];

        return [
            'id' => (int) $row->id,
            'order_sn' => (string) $row->order_sn,
            'num' => (float) $row->num,
            'fee' => (float) $row->fee,
            'receive_num' => (float) $row->receive_num,
            'channel' => (string) $row->channel,
            'real_name' => (string) $row->real_name,
            'pay_account' => (string) $row->pay_account,
            'status' => $statusMap[(int) $row->status] ?? 'pending',
            'remark' => (string) $row->remark,
        ];
    }
}
