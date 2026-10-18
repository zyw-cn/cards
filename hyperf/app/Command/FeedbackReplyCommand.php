<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Member\FeedbackService;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Command\Annotation\Command;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Input\InputArgument;
use function Hyperf\Support\make;

/**
 * 无后台时答复反馈：
 * php bin/hyperf.php feedback:reply 1 "感谢反馈，我们会持续优化"
 */
#[Command]
class FeedbackReplyCommand extends HyperfCommand
{
    public function __construct(protected ContainerInterface $container)
    {
        parent::__construct('feedback:reply');
    }

    public function configure(): void
    {
        parent::configure();
        $this->setDescription('答复一条大厅反馈');
        $this->addArgument('id', InputArgument::REQUIRED, '反馈 id');
        $this->addArgument('reply', InputArgument::REQUIRED, '答复内容');
        $this->addArgument('operator', InputArgument::OPTIONAL, '答复人', 'system');
    }

    public function handle(): void
    {
        $id = (int) $this->input->getArgument('id');
        $reply = (string) $this->input->getArgument('reply');
        $operator = (string) $this->input->getArgument('operator');
        try {
            $data = make(FeedbackService::class)->reply($id, $reply, $operator);
            $this->info('已答复 feedback#' . ($data['id'] ?? $id));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
    }
}
