<?php

declare(strict_types=1);

namespace App\Command;

use App\Services\Loyalty\LoyaltyService;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'loyalty:backfill',
    description: 'Backfill last_transaction_at, visit_count, and lifecycle_stage for all existing loyalty accounts.',
)]
class LoyaltyBackfillCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
        private readonly LoyaltyService $loyalty,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('company', 'c', InputOption::VALUE_OPTIONAL, 'Limit to a specific company ID');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print counts without writing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Loyalty Intelligence Backfill');

        $companyFilter = $input->getOption('company') ? (int) $input->getOption('company') : null;
        $dryRun        = (bool) $input->getOption('dry-run');

        if ($dryRun) {
            $io->warning('Dry-run mode — no writes will occur.');
        }

        // Fetch accounts to process
        $sql    = 'SELECT id, company_id, enrolled_at FROM loyalty_accounts';
        $params = [];
        if ($companyFilter !== null) {
            $sql    .= ' WHERE company_id = :cid';
            $params  = ['cid' => $companyFilter];
        }

        $accounts = $this->db->fetchAllAssociative($sql, $params);
        $total    = count($accounts);
        $io->text("Found {$total} loyalty accounts to process.");

        if ($total === 0) {
            $io->success('Nothing to do.');
            return Command::SUCCESS;
        }

        $io->progressStart($total);
        $updated = 0;
        $errors  = 0;

        foreach ($accounts as $account) {
            $accountId = (int) $account['id'];
            $companyId = (int) $account['company_id'];

            try {
                // Aggregate from ledger
                $ledgerStats = $this->db->fetchAssociative(
                    "SELECT
                        MAX(created_at)                                     AS last_txn,
                        SUM(CASE WHEN type = 'earn' THEN 1 ELSE 0 END)     AS visit_count
                       FROM loyalty_ledger
                      WHERE loyalty_account_id = :id
                        AND type IN ('earn', 'redeem')",
                    ['id' => $accountId],
                );

                $lastTransactionAt = $ledgerStats['last_txn'] ?? null;
                $visitCount        = (int) ($ledgerStats['visit_count'] ?? 0);

                // Compute lifecycle stage
                $now = new \DateTimeImmutable();
                if ($lastTransactionAt === null) {
                    $enrolledAt     = new \DateTimeImmutable($account['enrolled_at']);
                    $daysSinceEnroll = (int) $now->diff($enrolledAt)->days;
                    $stage = $daysSinceEnroll <= 14 ? 'new' : 'churned';
                } else {
                    $lastTxn   = new \DateTimeImmutable($lastTransactionAt);
                    $daysSince = (int) $now->diff($lastTxn)->days;
                    $stage = match (true) {
                        $daysSince <= 60  => 'active',
                        $daysSince <= 90  => 'at_risk',
                        $daysSince <= 180 => 'lapsing',
                        default           => 'churned',
                    };
                }

                if (!$dryRun) {
                    $this->db->executeStatement(
                        'UPDATE loyalty_accounts
                            SET last_transaction_at = :last_txn,
                                visit_count         = :vc,
                                lifecycle_stage     = :stage
                          WHERE id = :id',
                        [
                            'last_txn' => $lastTransactionAt,
                            'vc'       => $visitCount,
                            'stage'    => $stage,
                            'id'       => $accountId,
                        ],
                    );
                }

                $updated++;
            } catch (\Throwable $e) {
                $errors++;
                $io->warning("Account {$accountId}: {$e->getMessage()}");
            }

            $io->progressAdvance();
        }

        $io->progressFinish();

        if ($dryRun) {
            $io->success("Dry run complete. Would update {$updated} accounts.");
        } else {
            $io->success("Backfill complete. Updated: {$updated} | Errors: {$errors}");
        }

        // Print stage breakdown
        $breakdown = $this->db->fetchAllAssociative(
            'SELECT lifecycle_stage, COUNT(*) AS count FROM loyalty_accounts GROUP BY lifecycle_stage ORDER BY count DESC'
        );
        $io->table(['Stage', 'Count'], array_map(fn($r) => [$r['lifecycle_stage'], $r['count']], $breakdown));

        return Command::SUCCESS;
    }
}
