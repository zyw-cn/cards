<?php

declare(strict_types=1);

namespace App\Controller\Api;
use App\Controller\AbstractController;
use App\Request\MessageRequest;
use App\Service\Message\MessageService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\RequestMapping;

#[Controller(prefix: '/api/message')]
class MessageController extends AbstractController
{
    #[Inject]
    protected MessageService $messageService;

    /**
     * 发送邮箱验证码（注册 REGISTER / 忘记密码 FORGET）.
     *
     * POST /api/message/send
     * Body: mark, email, captcha, captcha_key
     */
    #[RequestMapping(path: 'send', methods: 'post')]
    public function send(MessageRequest $request)
    {
        $this->messageService->sendVerificationCode(
            (string) $request->input('mark'),
            (string) $request->input('email'),
            (string) $request->input('captcha'),
            (string) $request->input('captcha_key'),
            get_client_ip(),
            (string) $this->request->header('user-agent', ''),
        );

        return app('JSON')->success('验证码已发送，请查收邮件');
    }

}
