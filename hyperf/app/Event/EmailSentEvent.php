<?php

declare(strict_types=1);

namespace App\Event;


class EmailSentEvent
{
    public function __construct(
        public readonly Object $context,
    ) {
    }
}
