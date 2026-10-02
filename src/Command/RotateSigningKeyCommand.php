<?php

namespace App\Command;

use App\Service\LicenseSigner;
use App\Service\SigningKeyRotation;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:license:rotate', description: 'Signierschlüssel vorbereiten oder nach Verteilung der öffentlichen Schlüssel aktivieren.')]
class RotateSigningKeyCommand extends Command
{
    public function __construct(private readonly SigningKeyRotation $rotation, private readonly LicenseSigner $signer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::REQUIRED, 'prepare, activate oder public-keys')
            ->addArgument('kid', InputArgument::OPTIONAL, 'Vorbereitete Schlüsselkennung für activate');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        switch ($input->getArgument('action')) {
            case 'prepare':
                $id = $this->rotation->prepare();
                $output->writeln('Schlüssel vorbereitet: '.$id.'. Zuerst public-keys an Clients verteilen, dann activate '.$id.'.');
                break;
            case 'activate':
                $this->rotation->activate((string) $input->getArgument('kid'));
                $output->writeln('Signierschlüssel aktiviert. Alte Prüfschlüssel bleiben erhalten.');
                break;
            case 'public-keys':
                $output->writeln(json_encode($this->signer->publicKeys(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                break;
            default:
                throw new \InvalidArgumentException('Use prepare, activate or public-keys.');
        }

        return Command::SUCCESS;
    }
}
