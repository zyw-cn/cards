<?php

declare(strict_types=1);

namespace App\Listener;

use App\Dao\Member\MemberDao;
use App\Event\MemberRegisteredEvent;
use App\Model\Member\Member;
use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;

#[Listener]
class MemberRegisteredListener implements ListenerInterface
{
    public function __construct(
        protected MemberDao $memberDao,
    ) {
    }

    public function listen(): array
    {
        return [
            MemberRegisteredEvent::class,
        ];
    }

    public function process(object $event): void
    {
        if (! $event instanceof MemberRegisteredEvent) {
            return;
        }

        if ($event->referrerId <= 0) {
            return;
        }

        $memberId = (int) $event->member->id;
        /** @var Member|null $parent */
        $parent = $this->memberDao->getOne(['id' => $event->referrerId]);

        if ($parent === null || (int) $parent->status !== 1) {
            return;
        }

        $parentTree = trim((string) ($parent->tree ?? ''));
        $tree = $parentTree !== '' ? $parentTree . '|' . $parent->id : (string) $parent->id;

        $this->memberDao->update($memberId, [
            'pid' => $parent->id,
            'tree' => $tree,
        ]);
    }
}
