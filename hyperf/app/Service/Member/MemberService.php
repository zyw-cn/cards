<?php

declare(strict_types=1);

namespace App\Service\Member;

use App\Dao\Member\MemberDao;
use App\Exception\ApiException;
use App\Service\BaseService;
use Hyperf\Contract\ConfigInterface;
use Hyperf\HttpMessage\Upload\UploadedFile;

class MemberService extends BaseService
{
    public function __construct(
        MemberDao $dao,
        protected ConfigInterface $config,
    ) {
        $this->dao = $dao;
    }

    /**
     * 上传并更新会员头像.
     *
     * @return array{avatar: string, avatar_url: string}
     */
    public function uploadAvatar(int $memberId, UploadedFile $file): array
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new ApiException('头像上传失败，请重试');
        }

        $cfg = $this->config->get('upload.avatar', []);
        $maxSize = (int) ($cfg['max_size'] ?? 2 * 1024 * 1024);
        if ($file->getSize() > $maxSize) {
            throw new ApiException(sprintf('头像大小不能超过 %dMB', (int) ceil($maxSize / 1024 / 1024)));
        }

        $mime = $file->getMimeType();
        $allowedMimes = $cfg['allowed_mimes'] ?? [];
        if (! in_array($mime, $allowedMimes, true)) {
            throw new ApiException('不支持的头像格式');
        }

        $ext = strtolower($file->getExtension());
        $allowedExt = $cfg['allowed_extensions'] ?? [];
        if (! in_array($ext, $allowedExt, true)) {
            throw new ApiException('不支持的头像格式');
        }

        $member = $this->getOne(['id' => $memberId], 'id,avatar');
        if (! $member) {
            throw new ApiException('用户不存在');
        }

        $subdir = date('Y/m/d');
        $disk = rtrim((string) ($cfg['disk'] ?? ''), '/');
        $saveDir = $disk . '/avatar/' . $subdir;
        if (! is_dir($saveDir) && ! mkdir($saveDir, 0755, true) && ! is_dir($saveDir)) {
            throw new ApiException('创建上传目录失败');
        }

        $filename = $memberId . '_' . uniqid('', true) . '.' . $ext;
        $fullPath = $saveDir . '/' . $filename;
        $file->moveTo($fullPath);

        $relativePath = 'avatar/' . $subdir . '/' . $filename;
        $this->update($memberId, [
            'avatar'    => $relativePath,
            'update_at' => date('Y-m-d H:i:s'),
        ]);

        $this->deleteLocalAvatar((string) $member->avatar, $disk);

        return [
            'avatar'     => $relativePath,
            'avatar_url' => $this->buildAvatarUrl($relativePath),
        ];
    }

    public function buildAvatarUrl(string $relativePath): string
    {
        if ($relativePath === '') {
            return '';
        }

        if (str_starts_with($relativePath, 'http://') || str_starts_with($relativePath, 'https://')) {
            return $relativePath;
        }

        $base = rtrim((string) $this->config->get('upload.avatar.url', ''), '/');

        return $base . '/' . ltrim($relativePath, '/');
    }

    protected function deleteLocalAvatar(string $relativePath, string $disk): void
    {
        if ($relativePath === '' || ! str_starts_with($relativePath, 'avatar/')) {
            return;
        }

        $fullPath = $disk . '/' . $relativePath;
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
    }
}