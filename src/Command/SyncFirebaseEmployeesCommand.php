<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UtilisateurRepository;
use App\Service\FirebaseMobileService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:firebase:sync-employees',
    description: 'Sync all employees from MySQL to Firebase Auth + Firebase Realtime Database profiles.',
)]
final class SyncFirebaseEmployeesCommand extends Command
{
    public function __construct(
        private readonly UtilisateurRepository $utilisateurRepository,
        private readonly FirebaseMobileService $firebaseMobileService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('loop', null, InputOption::VALUE_NONE, 'Run forever and sync at regular intervals.')
            ->addOption('interval', null, InputOption::VALUE_REQUIRED, 'Seconds between sync loops (when --loop is used).', '60');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $loop = (bool) $input->getOption('loop');
        $interval = max(10, (int) $input->getOption('interval'));

        do {
            try {
                $users = $this->utilisateurRepository->findAll();
                $summary = $this->firebaseMobileService->syncEmployees($users);

                $io->success(sprintf(
                    'Sync done. Processed: %d | Created: %d | Updated: %d | Errors: %d',
                    $summary['processed'],
                    $summary['created'],
                    $summary['updated'],
                    $summary['errors'],
                ));

                if (($summary['errors'] ?? 0) > 0 && !empty($summary['error_messages'])) {
                    $io->warning('Employee sync errors:');

                    foreach ($summary['error_messages'] as $errorMessage) {
                        $io->writeln(' - ' . $errorMessage);
                    }
                }
            } catch (\Throwable $error) {
                $io->error('Sync failed: ' . $error->getMessage());
                if (!$loop) {
                    return Command::FAILURE;
                }
            }

            if (!$loop) {
                break;
            }

            $io->writeln(sprintf('Waiting %d seconds before next sync...', $interval));
            sleep($interval);
        } while (true);

        return Command::SUCCESS;
    }
}
