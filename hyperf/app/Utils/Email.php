<?php

declare(strict_types=1);

namespace App\Utils;


use App\Event\EmailSentEvent;
use App\Model\Sys\Template;
use App\Service\Sys\TemplateService;
use FriendsOfHyperf\Mail\Facade\Mail;
use FriendsOfHyperf\Mail\Message;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;

class Email
{
    /** 占位符格式：[!code]、[!company] */
    private const PLACEHOLDER_PATTERN = '/\[!(\w+)\]/';

    /** @var array<string, mixed> */
    private array $options = [];

    public function __construct(
        protected TemplateService $templateService,
        protected EventDispatcherInterface $eventDispatcher,
    ) {
        $this->options = $this->defaultOptions();
    }

    /**
     * 合并发送选项，返回新实例（不污染 DI 单例）.
     *
     * 支持键：to, cc, bcc, vars, subject, strict, mailer, reply_to, meta（ip/userAgent/city）,
     * from（字符串或 ['address'=>'', 'name'=>'']）, from_address, from_name, attachments
     *
     * 附件示例：
     * - '/path/file.pdf'
     * - ['path' => '...', 'as' => '报告.pdf', 'mime' => 'application/pdf']
     * - ['data' => $binary, 'as' => '报告.pdf', 'mime' => 'application/octet-stream']
     *
     * @param array<string, mixed> $options
     */
    public function withOptions(array $options): self
    {
        $instance = clone $this;
        $instance->options = $this->mergeOptions($this->options, $options);

        return $instance;
    }

    public function to(string|array $to): self
    {
        return $this->withOptions(['to' => $to]);
    }

    public function cc(string|array $cc): self
    {
        return $this->withOptions(['cc' => $cc]);
    }

    public function bcc(string|array $bcc): self
    {
        return $this->withOptions(['bcc' => $bcc]);
    }

    /** @param array<string, string|int|float> $vars */
    public function vars(array $vars): self
    {
        return $this->withOptions(['vars' => $vars]);
    }

    public function subject(?string $subject): self
    {
        return $this->withOptions(['subject' => $subject]);
    }

    public function strict(bool $strict = true): self
    {
        return $this->withOptions(['strict' => $strict]);
    }

    public function mailer(?string $mailer): self
    {
        return $this->withOptions(['mailer' => $mailer]);
    }

    public function from(string $address, ?string $name = null): self
    {
        return $this->withOptions(['from' => ['address' => $address, 'name' => $name]]);
    }

    public function replyTo(string|array $replyTo): self
    {
        return $this->withOptions(['reply_to' => $replyTo]);
    }

    public function meta(string $ip, string $userAgent = '', string $city = ''): self
    {
        return $this->withOptions(['meta' => ['ip' => $ip, 'userAgent' => $userAgent, 'city' => $city]]);
    }

    /**
     * @param string|array{path?: string, data?: mixed, as?: string, name?: string, mime?: string} $attachment
     */
    public function attach(string|array $attachment): self
    {
        $attachments = $this->options['attachments'];
        $attachments[] = $attachment;

        return $this->withOptions(['attachments' => $attachments]);
    }

    /**
     * 按模板 mark 发送邮件；成功返回 null，失败返回错误信息（并 dispatch EmailSentEvent 供监听器记库）.
     */
    public function send(string $mark): ?string
    {
        $mark = strtoupper(trim($mark));
        $template = $this->getTemplateByMark($mark);
        $templateId = (int) ($template['id'] ?? 0);
        $vars = (array) $this->options['vars'];
        $html = $this->render((string) $template['email_content'], $vars);
        $subject = (string) ($this->options['subject'] ?? ($template['title'] ?: '系统通知'));

        try {
            $to = $this->normalizeRecipients($this->options['to'], true);
        } catch (RuntimeException $e) {
            return $this->finish($templateId, '', $html, false, $e->getMessage(), $subject);
        }

        $primaryEmail = $to[0] ?? '';

        if ($this->options['strict'] && $this->hasUnresolvedPlaceholders($html)) {
            return $this->finish(
                $templateId,
                $primaryEmail,
                $html,
                false,
                sprintf('邮件模板 %s 存在未替换的占位符', $mark),
                $subject,
            );
        }

        $cc = $this->normalizeRecipients($this->options['cc'], false);
        $bcc = $this->normalizeRecipients($this->options['bcc'], false);

        $mailerName = $this->options['mailer'];
        $mailer = $mailerName ? Mail::mailer((string) $mailerName) : Mail::mailer();
        $from = $this->resolveFrom();

        try {
            $sent = $mailer->html($html, function (Message $message) use ($to, $cc, $bcc, $subject, $from): void {
                $message->to($to)->subject($subject);

                if ($cc !== []) {
                    $message->cc($cc);
                }
                if ($bcc !== []) {
                    $message->bcc($bcc);
                }
                if ($from !== null) {
                    $message->from($from['address'], $from['name']);
                }
                if ($this->options['reply_to']) {
                    $message->replyTo($this->options['reply_to']);
                }

                foreach ((array) $this->options['attachments'] as $attachment) {
                    $this->attachToMessage($message, $attachment);
                }
            });

            if ($sent === null) {
                return $this->finish(
                    $templateId,
                    $primaryEmail,
                    $html,
                    false,
                    '邮件未实际发出（可能被配置拦截）',
                    $subject,
                );
            }

            return $this->finish($templateId, $primaryEmail, $html, true, null, $subject);
        } catch (\Throwable $e) {
            return $this->finish($templateId, $primaryEmail, $html, false, $e->getMessage(), $subject);
        }
    }

    /**
     * 快捷发送：收件人 + 占位符.
     *
     * @param string|array<int, string> $emails
     * @param array<string, string|int|float> $vars
     */
    public function sendEmail(
        string $mark,
        string|array $emails,
        array $vars = [],
        ?string $subject = null,
        bool $strict = false,
    ): ?string {
        return $this->withOptions([
            'to' => $emails,
            'vars' => $vars,
            'subject' => $subject,
            'strict' => $strict,
        ])->send($mark);
    }

    public function getTemplateByMark(string $mark): array
    {
        $data = $this->templateService->getOne([
            'mark' => $mark,
            'status' => Template::StatusActive,
        ]);

        if (empty($data)) {
            throw new RuntimeException(sprintf('邮件模板不存在或未启用: %s', $mark));
        }

        return is_array($data) ? $data : $data->toArray();
    }

    public function getTemplateContentByMark(string $mark): string
    {
        return (string) $this->getTemplateByMark($mark)['email_content'];
    }

    /**
     * @param array<string, string|int|float> $vars
     */
    public function render(string $content, array $vars): string
    {
        return (string) preg_replace_callback(
            self::PLACEHOLDER_PATTERN,
            static function (array $matches) use ($vars): string {
                $key = $matches[1];
                if (! array_key_exists($key, $vars)) {
                    return $matches[0];
                }

                return (string) $vars[$key];
            },
            $content
        );
    }

    public function hasUnresolvedPlaceholders(string $content): bool
    {
        return (bool) preg_match(self::PLACEHOLDER_PATTERN, $content);
    }

    /** @return array<string, mixed> */
    protected function defaultOptions(): array
    {
        return [
            'to' => [],
            'cc' => [],
            'bcc' => [],
            'vars' => [],
            'subject' => null,
            'strict' => false,
            'mailer' => null,
            'from' => null,
            'reply_to' => null,
            'attachments' => [],
            'meta' => [],
        ];
    }

    protected function finish(
        int $templateId,
        string $email,
        string $content,
        bool $success,
        ?string $failReason,
        string $subject = '',
    ): ?string {
        $meta = (array) $this->options['meta'];
        $params=[
            "templateId"=>$templateId,
            "email"=>$email,
            "subject"=>$subject,
            "ip"=>$meta['ip'],
            "userAgent"=>$meta['userAgent'],
            "success"=>$success,
            "failReason"=>$failReason,
            "content"=>$content,
            "city"=>$meta['city'],
        ];
        $this->eventDispatcher->dispatch(new EmailSentEvent((Object)$params));

        return $success ? null : ($failReason ?? '邮件发送失败');
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function mergeOptions(array $base, array $overrides): array
    {
        $merged = array_replace_recursive($base, $overrides);

        if (isset($overrides['from_address'])) {
            $merged['from'] = [
                'address' => (string) $overrides['from_address'],
                'name' => $overrides['from_name'] ?? null,
            ];
        }

        if (isset($overrides['attachments']) && is_array($overrides['attachments'])) {
            $merged['attachments'] = array_values($overrides['attachments']);
        }

        if (isset($overrides['vars']) && is_array($overrides['vars'])) {
            $merged['vars'] = $overrides['vars'];
        }

        if (isset($overrides['meta']) && is_array($overrides['meta'])) {
            $merged['meta'] = $overrides['meta'];
        }

        return $merged;
    }

    /** @return null|array{address: string, name: ?string} */
    protected function resolveFrom(): ?array
    {
        $from = $this->options['from'];
        if ($from === null) {
            return null;
        }
        if (is_string($from)) {
            return ['address' => $from, 'name' => null];
        }
        if (is_array($from) && ! empty($from['address'])) {
            return [
                'address' => (string) $from['address'],
                'name' => $from['name'] ?? null,
            ];
        }

        return null;
    }

    /**
     * @param string|array<int, string> $emails
     * @return array<int, string>
     */
    protected function normalizeRecipients(string|array $emails, bool $required): array
    {
        $list = is_array($emails) ? $emails : [$emails];
        $list = array_values(array_filter(array_map(static fn ($email) => trim((string) $email), $list)));

        if ($list === []) {
            if ($required) {
                throw new RuntimeException('收件人不能为空');
            }

            return [];
        }

        foreach ($list as $email) {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException(sprintf('邮箱格式不正确: %s', $email));
            }
        }

        return $list;
    }

    /**
     * @param string|array{path?: string, data?: mixed, as?: string, name?: string, mime?: string} $attachment
     */
    protected function attachToMessage(Message $message, string|array $attachment): void
    {
        if (is_string($attachment)) {
            if (! is_file($attachment)) {
                throw new RuntimeException(sprintf('附件不存在: %s', $attachment));
            }
            $message->attach($attachment);

            return;
        }

        if (isset($attachment['data'])) {
            $message->attachData(
                $attachment['data'],
                (string) ($attachment['as'] ?? $attachment['name'] ?? 'attachment.bin'),
                ['mime' => $attachment['mime'] ?? null],
            );

            return;
        }

        if (isset($attachment['path'])) {
            if (! is_file($attachment['path'])) {
                throw new RuntimeException(sprintf('附件不存在: %s', $attachment['path']));
            }
            $message->attach($attachment['path'], [
                'as' => $attachment['as'] ?? null,
                'mime' => $attachment['mime'] ?? null,
            ]);

            return;
        }

        throw new RuntimeException('无效的附件格式，需为文件路径或含 path/data 的数组');
    }
}
