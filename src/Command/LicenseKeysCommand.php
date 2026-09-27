<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'app:license:keys', description: 'Erzeugt das Ed25519-Schlüsselpaar, ohne vorhandene Schlüssel zu ersetzen.')]
class LicenseKeysCommand extends Command
{
    public function __construct(#[Autowire('%kernel.project_dir%/config/license')] private readonly string $directory)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Schlüsselverzeichnis konnte nicht angelegt werden.');
        }
        $path = $this->directory.'/private.key';
        if (file_exists($path)) {
            $output->writeln('Signierschlüssel existiert bereits; keine Änderung.');

            return Command::SUCCESS;
        }
        $pair = sodium_crypto_sign_keypair();
        $oldMask = umask(0077);
        try {
            $handle = fopen($path, 'x');
            if (false === $handle) {
                throw new \RuntimeException('Schlüssel konnte nicht exklusiv angelegt werden.');
            }
            try {
                fwrite($handle, base64_encode(sodium_crypto_sign_secretkey($pair))."\n");
            } finally {
                fclose($handle);
            }
            file_put_contents($this->directory.'/public.key', base64_encode(sodium_crypto_sign_publickey($pair))."\n");
        } finally {
            umask($oldMask);
            sodium_memzero($pair);
        }
        $output->writeln('Schlüsselpaar in config/license erzeugt. Private Datei sicher sichern; nur public.key an Kunden weitergeben.');

        return Command::SUCCESS;
    }
}
