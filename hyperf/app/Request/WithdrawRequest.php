<?php

declare(strict_types=1);

namespace App\Request;

class WithdrawRequest extends BaseRequest
{
    protected array $scenes = [
        'submit' => ['money', 'channel', 'real_name', 'pay_account'],
    ];

    public function rules(): array
    {
        return [
            'money' => 'required|numeric|min:0.01',
            'channel' => 'required|in:wechat,alipay',
            'real_name' => 'required|string|max:20',
            'pay_account' => 'required|string|max:64',
        ];
    }

    public function messages(): array
    {
        return [
            'money.required' => '请输入金额',
            'money.numeric' => '金额格式不正确',
            'channel.required' => '请选择渠道',
            'channel.in' => '渠道无效',
            'real_name.required' => '请输入收款人姓名',
            'pay_account.required' => '请输入收款账号',
        ];
    }
}
