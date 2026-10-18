<?php

declare(strict_types=1);

namespace App\Listener;


use App\Event\EmailSentEvent;
use App\Model\Sys\TemplateContent;
use App\Service\Sys\TemplateContentService;
use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;

#[Listener]
class EmailSentListener implements ListenerInterface
{
    public function __construct(
        protected TemplateContentService $service,
    ) {
    }

    public function listen(): array
    {
        return [
            EmailSentEvent::class,
        ];
    }

    public function process(object $event): void
    {
        if (! $event instanceof EmailSentEvent) {
            return;
        }

        $ctx = $event->context;
        $this->service->save([
            'template_id' => $ctx->templateId,
            'email' => $ctx->email,
            'content' => $ctx->content,
            'ip' => $ctx->ip,
            'user_agent' => $ctx->userAgent,
            'city' => $ctx->city,
            'is_success' => $ctx->success ? TemplateContent::Success : TemplateContent::Failed,
            'fail_reason' => $ctx->failReason,
        ]);
    }
}
