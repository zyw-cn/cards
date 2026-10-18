<?php

declare(strict_types=1);

namespace App\Request;

class RechargeRequest extends BaseRequest
{
    protected array $scenes = [
        'submit' => ['money', 'channel', 'screenshot'],
        'cancel' => ['id'],
    ];

    public function rules(): array
    {
        return [
            'money' => 'required|numeric|min:0.01',
            'channel' => 'required|in:1,2',
            'screenshot' => 'required|file|mimes:jpeg,jpg,png,gif,webp|max:5120',
            'id' => 'required|integer|min:1',
        ];
    }

    public function messages(): array
    {
        return [
            'money.required' => '请输入金额',
            'money.numeric' => '金额格式不正确',
            'channel.required' => '请选择渠道',
            'channel.in' => '渠道无效',
            'screenshot.required' => '请上传转账截图',
            'screenshot.file' => '请上传转账截图',
            'screenshot.max' => '截图不能超过 5MB',
            'id.required' => '订单ID无效',
        ];
    }
}
