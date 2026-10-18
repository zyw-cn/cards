<?php

declare(strict_types=1);

namespace App\Service\Message;

use App\Constants\RedisKey;
use App\Dao\Member\MemberDao;
use App\Exception\ApiException;
use App\Utils\Captcha;
use App\Utils\Email;
use Firstphp\Ip2region\Ip2Region;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Redis\Redis;

class MessageService
{
    public function __construct(
        protected MemberDao $memberDao,
        protected Email $email,
        protected Captcha $captcha,
        protected Redis $redis,
        protected ConfigInterface $config,
        protected Ip2Region $ip2region
    ) {
    }

    /**
     * 发送邮箱验证码（注册 / 忘记密码）.
     */
    public function sendVerificationCode(
        string $mark,
        string $email,
        string $captcha,
        string $captchaKey,
        string $ip,
        string $userAgent = '',
    ): void {
        $mark = strtoupper(trim($mark));
        $email = strtolower(trim($email));
        $captcha = trim($captcha);
        $captchaKey = trim($captchaKey);
        // 前端若误把 Redis 完整键（如 c:captcha:xxxx / captcha:xxxx）传上来，兼容提取原始 key
        $captchaKey = preg_replace('/^(?:c:)?captcha:/', '', $captchaKey) ?? $captchaKey;
        $allowedMarks = (array) $this->config->get('message.allowed_marks', ['REGISTER', 'FORGET']);
        if (! in_array($mark, $allowedMarks, true)) {
            throw new ApiException('业务标识无效，仅支持 REGISTER 或 FORGET');
        }

        if ($mark === 'REGISTER' && $this->memberDao->be(['email' => $email])) {
            throw new ApiException('该邮箱已注册');
        }

        if ($mark === 'FORGET' && ! $this->memberDao->be(['email' => $email])) {
            throw new ApiException('该邮箱未注册');
        }

        if ($captcha === '' || $captchaKey === '') {
            throw new ApiException('图形验证码参数缺失');
        }

        if (! $this->captcha->verify($captcha, $captchaKey)) {
            throw new ApiException('图形验证码错误或已过期');
        }

        $limitKey = sprintf(RedisKey::SEND_LIMIT, $mark, $email);
        $limitTtl = $this->redis->ttl($limitKey);
        if ($limitTtl > 0) {
            throw new ApiException(sprintf('发送过于频繁，请%d秒后再试', $limitTtl));
        }

        $res = $this->ip2region->binarySearch($ip);
        $city = ! empty($res) ? $res['region'] : '';
        $code = (string) random_int(1000, 9999);
        $error = $this->email
            ->to($email)
            ->meta($ip, $userAgent, $city)
            ->vars([
                'code' => $code,
                'company' => (string) $this->config->get('message.company_name', ''),
            ])
            ->strict()
            ->send($mark);

        if ($error !== null) {
            throw new ApiException($error);
        }

        $codeKey = sprintf(RedisKey::REGISTER_KEY, $mark, $email);
        $codeTtl = (int) $this->config->get('message.code_ttl', 900);
        $sendInterval = (int) $this->config->get('message.send_interval', 60);
        $this->redis->setex($codeKey, $codeTtl, $code);
        $this->redis->setex($limitKey, $sendInterval, '1');
    }

    /**
     * 校验邮箱验证码（注册/重置密码提交时使用）.
     */
    public function verifyEmailCode(string $mark, string $email, string $code, bool $consume = true): bool
    {
        $mark = strtoupper(trim($mark));
        $email = strtolower(trim($email));
        $key = sprintf(RedisKey::REGISTER_KEY, $mark, $email);
        $cached = (string) $this->redis->get($key);

        if ($cached === '' || $cached !== trim($code)) {
            return false;
        }

        if ($consume) {
            $this->redis->del($key);
        }

        return true;
    }
}
