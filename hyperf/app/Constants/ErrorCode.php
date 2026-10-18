<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace App\Constants;

use Hyperf\Constants\AbstractConstants;
use Hyperf\Constants\Annotation\Constants;

#[Constants]
class ErrorCode extends AbstractConstants
{
    /**
     * @Message("Server Error！")
     */
    public const SERVER_ERROR = 500;
    public const ACCESS_TOKEN_EXPIRED = 1001;
    public const REFRESH_TOKEN_EXPIRED = 1002;

    public const ACCESS_TOKEN_TYPE_ERROR = 1003;

    public const ACCESS_TOKEN_INVALID = 1004;

    public const TOKEN_FAILED = 1005;
}
