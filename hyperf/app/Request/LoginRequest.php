<?php

declare(strict_types=1);

namespace App\Request;


class LoginRequest extends BaseRequest
{
    protected array $scenes = [
        'login'    => ['email', 'password'],
        'register' => ['email', 'password', 'confirm_password', 'invite_code', 'code', 'nickname'],
        'refresh_token'=>['refresh_token'],
        'forget'   => ['email', 'code', 'password', 'confirm_password'],
    ];

    public function rules(): array
    {
        return [
            'email'                 => 'required|email',
            'password'              => 'required|string|min:6',
            'invite_code'           => 'nullable|string|max:32',
            'refresh_token'           => 'required',
            'code'                    => 'required',
            'nickname'                => 'required|string|min:1|max:16',
            'pconfirm_password' => 'required|string|same:password',
        ];
    }

    public function messages(): array
    {
        return [
            'email.required'                 => '邮箱不能为空',
            'email.email'                    => '邮箱格式不正确',
            'password.required'              => '密码不能为空',
            'password.min'                   => '密码长度不能少于6位',
            'code.required'                  => '邮箱验证码必须填写',
            'nickname.required'              => '昵称不能为空',
            'nickname.min'                   => '昵称不能为空',
            'nickname.max'                   => '昵称不能超过16个字',
            'confirm_password.required'      => '确认密码不能为空',
            'confirm_password.same'          => '两次密码输入不一致',
            'refresh_token.required'=>'refresh_token can not empty'
        ];
    }
}
