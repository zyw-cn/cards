<?php

declare(strict_types=1);

namespace App\Event;

use App\Model\Member\Member;

class MemberLoginEvent
{
    public function __construct(
        public readonly Member $member,
        public readonly string $ip,
    ) {}
}
