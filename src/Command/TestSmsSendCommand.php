<?php

declare(strict_types=1);

namespace App\Command;

use App\Services\Sms\SmsService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test:sms-send',
    description: 'Queue a test SMS through the real SmsService and Messenger flow.',
)]
final class TestSmsSendCommand extends Command
{
    public function __construct(
        private readonly SmsService $smsService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('companyId', InputArgument::REQUIRED, 'Company ID to send under.')
            ->addArgument('recipient', InputArgument::REQUIRED, 'Recipient MSISDN, e.g. 2547XXXXXXXX.')
            ->addArgument('message', InputArgument::OPTIONAL, 'Message body.')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'transactional or promotional', 'transactional')
            ->addOption('config-id', null, InputOption::VALUE_REQUIRED, 'Specific sms_configs.id to use')
            ->addOption('branch-id', null, InputOption::VALUE_REQUIRED, 'Optional branch ID for outbox context')
            ->addOption('customer-id', null, InputOption::VALUE_REQUIRED, 'Optional customer ID for outbox context')
            ->addOption('loyalty-account-id', null, InputOption::VALUE_REQUIRED, 'Optional loyalty account ID for outbox context')
            ->addOption('notification-id', null, InputOption::VALUE_REQUIRED, 'Optional loyalty notification ID for outbox context');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $companyId = (int) $input->getArgument('companyId');
        $recipient = trim((string) $input->getArgument('recipient'));
        $message   = trim((string) ($input->getArgument('message') ?? ''));
        $type      = strtolower(trim((string) $input->getOption('type')));
        $configId  = $input->getOption('config-id');

        if ($message === '') {
            $message = sprintf(
                'Patronr SMS test sent at %s',
                (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Nairobi')))->format('Y-m-d H:i:s'),
            );
        }

        if (!in_array($type, ['transactional', 'promotional'], true)) {
            $io->error('The --type option must be either "transactional" or "promotional".');

            return Command::INVALID;
        }

        $context = array_filter([
            'branch_id'               => $this->nullableInt($input->getOption('branch-id')),
            'customer_id'             => $this->nullableInt($input->getOption('customer-id')),
            'loyalty_account_id'      => $this->nullableInt($input->getOption('loyalty-account-id')),
            'loyalty_notification_id' => $this->nullableInt($input->getOption('notification-id')),
        ], static fn (mixed $value): bool => $value !== null);

        try {
            $outboxId = $type === 'promotional'
                ? $this->smsService->queuePromotional($companyId, $recipient, $message, $this->nullableInt($configId), $context)
                : $this->smsService->queueTransactional($companyId, $recipient, $message, $this->nullableInt($configId), $context);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Queued SMS successfully. sms_outbox.id = %d', $outboxId));
        $io->writeln('If the notifications worker is running under Supervisor, it should pick this up automatically.');

        return Command::SUCCESS;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
