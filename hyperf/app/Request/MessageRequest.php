<?php

declare(strict_types=1);

namespace App\Request;

class MessageRequest extends BaseRequest
{

    public function rules(): array
    {
        return [
            'mark' => 'required|string|in:REGISTER,FORGET,register,forget',
            'email' => 'required|email',
            'captcha' => 'required|string',
            'captcha_key' => 'required|string',
        ];
    }

    public function messages(): array
    {
        return [
            'mark.required' => '业务标识不能为空',
            'mark.in' => '业务标识无效，仅支持 REGISTER 或 FORGET',
            'email.required' => '邮箱不能为空',
            'email.email' => '邮箱格式不正确',
            'captcha.required' => '请输入图形验证码',
            'captcha_key.required' => '缺少图形验证码标识',
        ];
    }
}
