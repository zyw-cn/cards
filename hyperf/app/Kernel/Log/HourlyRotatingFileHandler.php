<?php

declare(strict_types=1);

namespace App\Kernel\Log;

use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;

/**
 * 按小时切割日志文件
 *
 * 父类 RotatingFileHandler 只支持 Y/m/d 三级切割，
 * 本类扩展支持小时(H)，覆盖三个方法：
 *  - setDateFormat()   放开对 H 的校验
 *  - getNextRotation() 下次切割时间改为下一整点
 *  - getGlobPattern()  glob 匹配加上 H 的通配符
 *
 * 文件名示例: info-2026-04-14-15.log
 */
class HourlyRotatingFileHandler extends RotatingFileHandler
{
    public const FILE_PER_HOUR = 'Y-m-d-H';

    public function __construct(
        string $filename,
        int $maxFiles = 0,
        int|string|Level $level = Level::Debug,
        bool $bubble = true,
        ?int $filePermission = null,
        bool $useLocking = false,
    ) {
        parent::__construct(
            $filename,
            $maxFiles,
            $level,
            $bubble,
            $filePermission,
            $useLocking,
            self::FILE_PER_HOUR,
        );
    }

    protected function setDateFormat(string $dateFormat): void
    {
        // 父类正则不允许 H，这里直接赋值
        $this->dateFormat = $dateFormat;
    }

    protected function getNextRotation(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('next hour'))->setTime(
            (int) (new \DateTimeImmutable('next hour'))->format('H'),
            0,
            0
        );
    }

    protected function getGlobPattern(): string
    {
        $fileInfo = pathinfo($this->filename);
        $glob = str_replace(
            ['{filename}', '{date}'],
            [
                $fileInfo['filename'],
                str_replace(
                    ['Y', 'y', 'm', 'd', 'H'],
                    ['[0-9][0-9][0-9][0-9]', '[0-9][0-9]', '[0-9][0-9]', '[0-9][0-9]', '[0-9][0-9]'],
                    $this->dateFormat
                ),
            ],
            ($fileInfo['dirname'] ?? '') . '/' . $this->filenameFormat
        );

        if (isset($fileInfo['extension'])) {
            $glob .= '.' . $fileInfo['extension'];
        }

        return $glob;
    }
}
