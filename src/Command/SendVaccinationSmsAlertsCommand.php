<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\VaccinationRepository;
use App\Service\TwilioSmsService;
use App\Service\VaccinationSmsAlertService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:vaccination:send-sms-alerts',
    description: 'Send SMS alerts when date_done->date_next interval is within 2 days.'
)]
final class SendVaccinationSmsAlertsCommand extends Command
{
    public function __construct(
        private readonly VaccinationSmsAlertService $smsAlertService,
        private readonly VaccinationRepository $vaccinationRepository,
        private readonly TwilioSmsService $twilioSmsService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Display interval-eligible alerts without sending SMS.');
        $this->addOption('verify-key', null, InputOption::VALUE_NONE, 'Verify Twilio API key configuration and connectivity.');
        $this->addOption('send-test', null, InputOption::VALUE_NONE, 'Send one test SMS to TWILIO_TO_PHONE.');
        $this->addOption('to', null, InputOption::VALUE_REQUIRED, 'Destination phone override for --send-test (E.164 or local format).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ((bool) $input->getOption('verify-key')) {
            if (!$this->twilioSmsService->isConfigured()) {
                $io->error($this->twilioSmsService->getLastError() ?? 'Twilio API key configuration is incomplete in .env.');
                return Command::FAILURE;
            }

            if (!$this->twilioSmsService->verifyApiKey()) {
                $io->error($this->twilioSmsService->getLastError() ?? 'Twilio API key verification failed.');
                return Command::FAILURE;
            }

            $io->success('Twilio API key verification succeeded.');
            return Command::SUCCESS;
        }

        if ((bool) $input->getOption('send-test')) {
            if (!$this->twilioSmsService->isConfigured()) {
                $io->error($this->twilioSmsService->getLastError() ?? 'Twilio API key configuration is incomplete in .env.');
                return Command::FAILURE;
            }

            $overrideTo = trim((string) ($input->getOption('to') ?? ''));
            $sent = $overrideTo !== ''
                ? $this->twilioSmsService->sendSms($overrideTo, 'Test SMS Symfony: integration Twilio API key active.')
                : $this->twilioSmsService->sendToDefaultPhone('Test SMS Symfony: integration Twilio API key active.');

            if (!$sent) {
                $io->error($this->twilioSmsService->getLastError() ?? 'Test SMS failed to send.');
                return Command::FAILURE;
            }

            $io->success('Test SMS sent successfully.');
            return Command::SUCCESS;
        }

        if ((bool) $input->getOption('dry-run')) {
            $eligible = $this->vaccinationRepository->findEligibleForIntervalSmsAlerts(2);
            $io->success(sprintf('%d vaccination(s) will trigger SMS alerts.', count($eligible)));

            if ($eligible !== []) {
                $rows = array_map(
                    static fn (array $item): array => [
                        (string) ($item['id_vaccination'] ?? ''),
                        (string) ($item['animal_type'] ?? ''),
                        (string) ($item['vaccine_name'] ?? ''),
                        (string) ($item['date_done'] ?? ''),
                        (string) ($item['date_next'] ?? ''),
                        (string) ($item['interval_days'] ?? ''),
                        (string) ($item['status'] ?? ''),
                    ],
                    $eligible
                );

                $io->table(['ID', 'Animal', 'Vaccine', 'Done Date', 'Next Date', 'Interval (days)', 'Status'], $rows);
            }

            return Command::SUCCESS;
        }

        $sent = $this->smsAlertService->checkAndSendAlerts(2);
        $io->success(sprintf('%d SMS alert(s) sent.', $sent));

        return Command::SUCCESS;
    }
}
