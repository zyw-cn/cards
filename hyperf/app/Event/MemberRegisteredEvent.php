<?php

declare(strict_types=1);

namespace App\Event;

use App\Model\Member\Member;

class MemberRegisteredEvent
{
    public function __construct(
        public readonly Member $member,
        public readonly int $referrerId,
    ) {
    }
}
