<?php

declare(strict_types=1);

namespace App\Service\Member;

use App\Dao\Member\MemberDao;
use App\Event\MemberLoginEvent;
use App\Event\MemberRegisteredEvent;
use App\Exception\ApiException;
use App\Service\BaseService;
use App\Service\Message\MessageService;
use App\Utils\WebSocketKick;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Di\Annotation\Inject;
use HyperfExtension\Jwt\Contracts\JwtFactoryInterface;
use HyperfExtension\Jwt\Jwt;
use Psr\EventDispatcher\EventDispatcherInterface;

class LoginService extends BaseService
{


    #[Inject]
    protected EventDispatcherInterface $eventDispatcher;

    #[Inject]
    protected MessageService $messageService;

    protected Jwt $jwt;

    protected int $accessTokenTtl;

    protected int $refreshTokenTtl;

    public function __construct(JwtFactoryInterface $jwtFactory, ConfigInterface $config,MemberDao $dao)
    {
        $this->jwt = $jwtFactory->make();
        $this->accessTokenTtl  = (int) $config->get('jwt.ttl', 7200);
        $this->refreshTokenTtl = (int) $config->get('jwt.refresh_ttl', 604800);
        $this->dao = $dao;
    }

    public function login(string $email, string $password, string $clientIp): array
    {
        $member = $this->dao->getOne(['email' => $email],"id,status,passwd,email");
        if (!$member || !password_verify($password, $member->passwd)) {
            throw new ApiException('邮箱或密码错误');
        }

        if ($member->status !== 1) {
            throw new ApiException('账号已被禁用');
        }

        $this->eventDispatcher->dispatch(new MemberLoginEvent($member, $clientIp));

        // 新登录踢掉旧长连接，保证同账号仅一处在线
        WebSocketKick::disconnectUid((int) $member->id);

        return $this->buildTokenData($member->id);
    }

    public function register(string $email, string $password, string $emailCode, string $nickname, string $inviteCode = '',string $clientIp = ''): void
    {
        $email = strtolower(trim($email));
        $nickname = trim($nickname);
        if ($nickname === '') {
            throw new ApiException('昵称不能为空');
        }
        if (mb_strlen($nickname) > 16) {
            throw new ApiException('昵称不能超过16个字');
        }

        if ($this->dao->be(['email' => $email])) {
            throw new ApiException('该邮箱已注册');
        }

        if (! $this->messageService->verifyEmailCode('REGISTER', $email, $emailCode)) {
            throw new ApiException('邮箱验证码错误或已过期');
        }

        $referrerId = 0;
        $inviteCode = trim($inviteCode);
        if ($inviteCode !== '') {
            $referrer = $this->dao->getOne(['invite_code' => $inviteCode], 'id,status');
            if (! $referrer || (int) $referrer->status !== 1) {
                throw new ApiException('无效的邀请码');
            }
            $referrerId = (int) $referrer->id;
        }

        $memberId = $this->dao->save([
            'email'       => $email,
            'passwd'      => password_hash($password, PASSWORD_BCRYPT),
            'register_at' => date('Y-m-d H:i:s'),
            'login_at'    => date('Y-m-d H:i:s'),
            'login_ip'    => $clientIp,
            'status'      => 1,
            'nickname'    => $nickname,
        ]);

        $member = $this->dao->getOne(['id' => $memberId]);
        if ($member) {
            $this->eventDispatcher->dispatch(new MemberRegisteredEvent($member, $referrerId));
        }
    }

    public function forgetPassword(string $email, string $code, string $password): void
    {
        $email = strtolower(trim($email));

        $member = $this->dao->getOne(['email' => $email], 'id,status');
        if (! $member) {
            throw new ApiException('该邮箱未注册');
        }

        if ((int) $member->status !== 1) {
            throw new ApiException('账号已被禁用');
        }

        if (! $this->messageService->verifyEmailCode('FORGET', $email, $code)) {
            throw new ApiException('邮箱验证码错误或已过期');
        }

        $this->dao->update((int) $member->id, [
            'passwd' => password_hash($password, PASSWORD_BCRYPT),
        ]);
    }

    public function refreshToken(string $refreshToken): array
    {
        try {
            $this->jwt->setToken($refreshToken);
            $payload = $this->jwt->getPayload();
        } catch (\Throwable $e) {
            throw new ApiException( $e->getMessage(),4012);
        }

        if ($payload->get('token_type') !== 'refresh') {
            throw new ApiException( '无效的 token 类型',4014);
        }

        $member = $this->dao->getOne(['id' => $payload->get('sub')]);

        if (!$member || $member->status !== 1) {
            throw new ApiException('账号不存在或已被禁用');
        }

        return [
            'access_token' => $this->generateToken((int) $member->id, 'access', $this->accessTokenTtl),
            'expires_in'   => $this->accessTokenTtl,
        ];
    }

    protected function buildTokenData(int $memberId): array
    {
        $accessToken  = $this->generateToken($memberId, 'access', $this->accessTokenTtl);
        $refreshToken = $this->generateToken($memberId, 'refresh', $this->refreshTokenTtl);

        return [
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in'    => $this->accessTokenTtl,
        ];
    }

    protected function generateToken(int $memberId, string $tokenType, int $ttl): string
    {
        $this->jwt->getPayloadFactory()->setTtl($ttl);
        $claims = [
            'sub'        => $memberId,
            'token_type' => $tokenType,
        ];
        $payload = $this->jwt->getPayloadFactory()->make($claims);
        return $this->jwt->getManager()->encode($payload)->get();
    }
}
