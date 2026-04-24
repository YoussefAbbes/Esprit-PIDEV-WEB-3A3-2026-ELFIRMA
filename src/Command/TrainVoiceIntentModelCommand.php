<?php

namespace App\Command;

use App\AI\VoiceIntentAi;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:ai:train-voice-intent', description: 'Train and save local voice intent AI model')]
final class TrainVoiceIntentModelCommand extends Command
{
    public function __construct(private readonly VoiceIntentAi $voiceIntentAi)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Training voice intent AI model');

        $result = $this->voiceIntentAi->trainAndSave();

        $io->success('Voice intent model trained successfully.');
        $io->table(['Metric', 'Value'], [
            ['Trained at', $result['trained_at']],
            ['Examples', (string) $result['examples']],
            ['Classes', (string) $result['classes']],
            ['Model file', $result['model_path']],
        ]);

        return Command::SUCCESS;
    }
}
