<?php

declare(strict_types=1);

namespace App\Request;

class MemberRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'avatar' => 'required|file|mimes:jpeg,jpg,png,gif,webp|max:2048',
        ];
    }

    public function messages(): array
    {
        return [
            'avatar.required' => '请上传头像文件',
            'avatar.file'     => '头像必须是文件',
            'avatar.mimes'    => '头像仅支持 jpg、png、gif、webp 格式',
            'avatar.max'      => '头像大小不能超过 2MB',
        ];
    }
}
