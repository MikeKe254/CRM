<?php

declare(strict_types=1);

namespace App\Command;

use App\Async\Inbox\ExternalEventInboxRepository;
use App\Async\Inbox\ExternalEventMessageMapper;
use App\Async\Inbox\UnknownEventTypeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:async:inbox-relay',
    description: 'Relay pending external_event_inbox rows into Messenger transports.',
)]
final class ExternalEventInboxRelayCommand extends Command
{
    public function __construct(
        private readonly ExternalEventInboxRepository $inbox,
        private readonly ExternalEventMessageMapper $mapper,
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('batch-size', null, InputOption::VALUE_OPTIONAL, 'How many rows to claim each loop', '20')
            ->addOption('sleep-ms', null, InputOption::VALUE_OPTIONAL, 'How long to sleep when idle', '500')
            ->addOption('once', null, InputOption::VALUE_NONE, 'Process one claim cycle and exit');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->inbox->ensureTable();

        $batchSize = max(1, (int) $input->getOption('batch-size'));
        $sleepMs = max(100, (int) $input->getOption('sleep-ms'));
        $once = (bool) $input->getOption('once');

        $workerId = sprintf('relay-%s-%d', gethostname() ?: 'host', getmypid());
        $io->writeln(sprintf('External inbox relay running as <info>%s</info>', $workerId));

        $loopIteration = 0;

        do {
            // Every 12 iterations (~1 minute at 500 ms sleep) rescue rows that got
            // stuck in `processing` due to a previous worker crash.
            if ($loopIteration % 12 === 0) {
                $rescued = $this->inbox->rescueStale(olderThanMinutes: 5);
                if ($rescued > 0) {
                    $io->writeln(sprintf('<comment>Rescued %d stale processing row(s)</comment>', $rescued));
                }
            }
            $loopIteration++;

            $claimToken = sprintf('%s-%s', $workerId, bin2hex(random_bytes(6)));
            $rows = $this->inbox->claimBatch($batchSize, $claimToken);

            foreach ($rows as $row) {
                $id           = (int) $row['id'];
                $attemptCount = (int) ($row['attempt_count'] ?? 1);
                $maxAttempts  = (int) ($row['max_attempts'] ?? 10);

                try {
                    $message = $this->mapper->map($row);
                    $this->bus->dispatch($message);
                    $this->inbox->markProcessed($id);
                } catch (UnknownEventTypeException $e) {
                    // No handler will ever exist for this type at runtime — mark dead
                    // immediately rather than burning through retry attempts.
                    $this->inbox->markDead($id, $e->getMessage());
                    $io->writeln(sprintf('<error>Dead (unknown type): inbox #%d — %s</error>', $id, $e->getMessage()));
                } catch (\Throwable $exception) {
                    $this->inbox->markFailed(
                        $id,
                        $exception->getMessage(),
                        $attemptCount,
                        $maxAttempts,
                    );
                }
            }

            if ($once) {
                break;
            }

            if ($rows === []) {
                usleep($sleepMs * 1000);
            }
        } while (true);

        return Command::SUCCESS;
    }
}
