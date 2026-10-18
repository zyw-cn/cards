<?php

declare(strict_types=1);

namespace App\Service\Member;

use App\Dao\Member\MemberRechargeDao;
use App\Exception\ApiException;
use App\Service\BaseService;
use Hyperf\Contract\ConfigInterface;
use Hyperf\HttpMessage\Upload\UploadedFile;

class RechargeService extends BaseService
{
    /** 金币与充值金额比例：1 元 = 1 金币（points 与充值金额 1:1） */
    public const COIN_PER_RMB = 1;

    public const STATUS_PENDING = 1;

    public const STATUS_APPROVED = 2;

    public const STATUS_REJECTED = -1;

    /** 充值表 channel 为 tinyint：1=支付宝，2=微信 */
    public const CHANNEL_ALIPAY = 1;

    public const CHANNEL_WECHAT = 2;

    public function __construct(
        protected MemberRechargeDao $rechargeDao,
        protected FinanceService $financeService,
        protected ConfigInterface $config,
    ) {
        $this->dao = $rechargeDao;
    }

    public function payConfig(): array
    {
        $cfg = $this->config->get('finance.pay', []);

        return [
            'coin_per_rmb' => self::COIN_PER_RMB,
            'max_recharge_rmb' => (int) ($cfg['max_recharge_rmb'] ?? 5000),
            'wechat' => [
                'name' => (string) ($cfg['wechat_name'] ?? '游戏官方收款（微信）'),
                'no' => (string) ($cfg['wechat_no'] ?? 'ddz_pay_official'),
            ],
            'alipay' => [
                'name' => (string) ($cfg['alipay_name'] ?? '游戏官方收款（支付宝）'),
                'no' => (string) ($cfg['alipay_no'] ?? 'pay@ddz-demo.com'),
            ],
        ];
    }

    /**
     * 提交充值申请：上传转账截图，生成待审核订单（审核通过后才入账）。
     */
    public function submitRecharge(int $memberId, float $money, int $channel, UploadedFile $file): int
    {
        $pay = $this->payConfig();
        if ($money <= 0) {
            throw new ApiException('请输入充值金额');
        }
        if ($money > $pay['max_recharge_rmb']) {
            throw new ApiException(sprintf('单次充值不超过 %d 元', $pay['max_recharge_rmb']));
        }
        if (! in_array($channel, [self::CHANNEL_WECHAT,self::CHANNEL_ALIPAY], true)) {
            throw new ApiException('请选择收款渠道');
        }
        $screenshotPath = $this->saveScreenshot($memberId, $file);
        $orderSn = $this->buildOrderSn($memberId);
        return $this->rechargeDao->create([
            'member_id' => $memberId,
            'order_sn' => $orderSn,
            'money' => bcadd((string) $money, '0', 2),
            'channel' => $channel,
            'screenshot' => $screenshotPath,
            'status' => self::STATUS_PENDING
        ]);
    }




    public function rechargeList(int $memberId): array
    {
        $rows = $this->rechargeDao->selectList(
            ['member_id' => $memberId],
            '*',
            0,
            0,
            ['id' => 'desc']
        );

        return [
            'list' => array_map(fn ($row) => $this->formatRow($row), $rows->all()),
        ];
    }

    public function cancelRecharge(int $memberId, int $orderId): bool
    {
        $order = $this->rechargeDao->getOne(['id' => $orderId, 'member_id' => $memberId]);
        if (! $order) {
            throw new ApiException('订单不存在');
        }
        if ((int) $order->status !== self::STATUS_PENDING) {
            throw new ApiException('仅待审核订单可撤销');
        }

        return $this->rechargeDao->delete($orderId);
    }

    public function buildScreenshotUrl(string $relativePath): string
    {
        if ($relativePath === '') {
            return '';
        }
        if (str_starts_with($relativePath, 'http://') || str_starts_with($relativePath, 'https://')) {
            return $relativePath;
        }
        if (str_starts_with($relativePath, 'data:')) {
            return $relativePath;
        }

        $base = rtrim((string) $this->config->get('upload.recharge.url', ''), '/');

        return $base . '/' . ltrim($relativePath, '/');
    }

    protected function saveScreenshot(int $memberId, UploadedFile $file): string
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new ApiException('截图上传失败');
        }

        $cfg = $this->config->get('upload.recharge', []);
        $maxSize = (int) ($cfg['max_size'] ?? 5 * 1024 * 1024);
        if ($file->getSize() > $maxSize) {
            throw new ApiException('截图不能超过 5MB');
        }

        $mime = $file->getMimeType();
        $allowedMimes = $cfg['allowed_mimes'] ?? ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (! in_array($mime, $allowedMimes, true)) {
            throw new ApiException('截图格式不支持');
        }

        $ext = strtolower($file->getExtension());
        $allowedExt = $cfg['allowed_extensions'] ?? ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (! in_array($ext, $allowedExt, true)) {
            throw new ApiException('截图格式不支持');
        }

        $subdir = date('Y/m/d');
        $disk = rtrim((string) ($cfg['disk'] ?? ''), '/');
        $saveDir = $disk . '/recharge/' . $subdir;
        if (! is_dir($saveDir) && ! mkdir($saveDir, 0755, true) && ! is_dir($saveDir)) {
            throw new ApiException('创建上传目录失败');
        }

        $filename = $memberId . '_' . uniqid('', true) . '.' . $ext;
        $file->moveTo($saveDir . '/' . $filename);

        return 'recharge/' . $subdir . '/' . $filename;
    }


    /** 生成充值流水单号：R + 时间 + 会员ID + 随机数 */
    protected function buildOrderSn(int $memberId): string
    {
        return 'R' . date('YmdHis') . $memberId . random_int(1000, 9999);
    }
}
