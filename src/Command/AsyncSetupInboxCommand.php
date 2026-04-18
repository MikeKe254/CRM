<?php

declare(strict_types=1);

namespace App\Command;

use App\Async\Inbox\ExternalEventInboxRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:async:setup-inbox',
    description: 'Create the external_event_inbox table if it does not exist.',
)]
final class AsyncSetupInboxCommand extends Command
{
    public function __construct(
        private readonly ExternalEventInboxRepository $inbox,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->inbox->ensureTable();
        $io->success('external_event_inbox is ready.');

        return Command::SUCCESS;
    }
}
