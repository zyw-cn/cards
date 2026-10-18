<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Controller\AbstractController;
use App\Request\LoginRequest;
use App\Service\Member\LoginService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\Validation\Annotation\Scene;
use App\Utils\Captcha;
use Hyperf\HttpServer\Annotation\RequestMapping;
#[Controller(prefix: '/api/login')]
class LoginController extends AbstractController
{
    #[Inject]
    protected LoginService $loginService;

    #[Scene(scene: 'login')]
    #[RequestMapping(path: "index", methods: "post")]
    public function index(LoginRequest $request)
    {
        $data = $this->loginService->login(
            $request->input('email'),
            $request->input('password'),
            get_client_ip(),
        );

        return app("JSON")->data($data);
    }

    #[Scene(scene: 'register')]
    #[RequestMapping(path: "register", methods: "post")]
    public function register(LoginRequest $request)
    {
        $this->loginService->register(
            $request->input('email'),
            $request->input('password'),
            $request->input("code"),
            $request->input('nickname', ''),
            (string) $request->input('invite_code', ''),
            get_client_ip(),
        );
        return app('JSON')->success('注册成功');
    }

    #[Scene(scene: 'refresh_token')]
    #[RequestMapping(path: "refresh-token", methods: "post")]
    public function refreshToken(LoginRequest $request)
    {
        $data = $this->loginService->refreshToken(
            $request->input('refresh_token', ''),
        );

        return app('JSON')->data($data,'刷新成功');
    }


    #[RequestMapping(path: "captcha", methods: "post")]
    public function captcha()
    {
        $captcha = new Captcha();
        $data = $captcha->create();
        return app("JSON")->data($data,"获取验证码成功");
    }

    #[Scene(scene: 'forget')]
    #[RequestMapping(path: "forget", methods: "post")]
    public function forget(LoginRequest $request)
    {
        $this->loginService->forgetPassword(
            (string) $request->input('email'),
            (string) $request->input('code'),
            (string) $request->input('password'),
        );

        return app('JSON')->success('密码重置成功');
    }
}
