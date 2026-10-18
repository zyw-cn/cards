<?php

declare(strict_types=1);

namespace App\Listener;

use App\Event\MemberLoginEvent;
use App\Facades\Log;
use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;

#[Listener]
class MemberLoginListener implements ListenerInterface
{
    public function listen(): array
    {
        return [
            MemberLoginEvent::class,
        ];
    }

    public function process(object $event): void
    {
        if (!$event instanceof MemberLoginEvent) {
            return;
        }

        $member = $event->member;
        $member->login_at = date('Y-m-d H:i:s');
        $member->login_ip = $event->ip;
        $member->save();

        Log::info('member.login', [
            'member_id' => $member->id,
            'email'     => $member->email,
            'ip'        => $event->ip,
        ]);
    }
}
