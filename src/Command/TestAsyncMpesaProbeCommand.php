<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\Test\ProcessMpesaPaymentProbeMessage;
use App\Services\Test\AsyncMpesaProbeService;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:test:async-mpesa-probe',
    description: 'Queue a disposable async test that processes mpesa_payments into async_mpesa_probe_logs.',
)]
final class TestAsyncMpesaProbeCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
        private readonly MessageBusInterface $bus,
        private readonly AsyncMpesaProbeService $probeService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'How many mpesa_payments rows to queue', '5')
            ->addOption('payment-id', null, InputOption::VALUE_OPTIONAL, 'Queue one specific mpesa_payments id')
            ->addOption('show-log', null, InputOption::VALUE_NONE, 'Show the latest rows from async_mpesa_probe_logs after dispatching');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->probeService->ensureProbeTable();

        $paymentId = $input->getOption('payment-id');
        $limit = max(1, (int) $input->getOption('limit'));

        if ($paymentId !== null) {
            $paymentIds = [(int) $paymentId];
        } else {
            $rows = $this->db->fetchAllAssociative(
                'SELECT id
                   FROM mpesa_payments
                  ORDER BY id DESC
                  LIMIT ' . $limit
            );

            $paymentIds = array_map(
                static fn (array $row): int => (int) $row['id'],
                $rows,
            );
        }

        if ($paymentIds === []) {
            $io->warning('No mpesa_payments rows found to queue.');
            return Command::SUCCESS;
        }

        foreach ($paymentIds as $queuedPaymentId) {
            $this->bus->dispatch(new ProcessMpesaPaymentProbeMessage($queuedPaymentId));
        }

        $io->success(sprintf(
            'Queued %d async probe message(s) for mpesa_payments: %s',
            count($paymentIds),
            implode(', ', $paymentIds),
        ));

        $io->text('Supervisor worker should consume these from the async transport and write into async_mpesa_probe_logs.');

        if ((bool) $input->getOption('show-log')) {
            $rows = $this->db->fetchAllAssociative(
                'SELECT id, mpesa_payment_id, transaction_id, amount, processed_by, notes, created_at
                   FROM async_mpesa_probe_logs
                  ORDER BY id DESC
                  LIMIT 10'
            );

            if ($rows === []) {
                $io->note('Probe table is ready, but no processed rows are visible yet.');
            } else {
                $io->table(
                    ['ID', 'Payment ID', 'Transaction', 'Amount', 'Processed By', 'Notes', 'Created At'],
                    array_map(
                        static fn (array $row): array => [
                            (string) $row['id'],
                            (string) $row['mpesa_payment_id'],
                            (string) ($row['transaction_id'] ?? ''),
                            (string) ($row['amount'] ?? ''),
                            (string) $row['processed_by'],
                            (string) $row['notes'],
                            (string) $row['created_at'],
                        ],
                        $rows,
                    ),
                );
            }
        }

        $io->text('You can verify processing with: SELECT * FROM async_mpesa_probe_logs ORDER BY id DESC;');

        return Command::SUCCESS;
    }
}
