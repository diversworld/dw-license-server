<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\AuditIntegrity;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:audit:verify', description: 'Verify every audit hash and export an external integrity checkpoint.')]
final class VerifyAuditCommand extends Command
{
    public function __construct(private readonly AuditIntegrity $integrity) { parent::__construct(); }
    protected function configure(): void { $this->addOption('checkpoint', null, InputOption::VALUE_NONE, 'Output a JSON checkpoint only when verification succeeds.'); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try { $result = $this->integrity->verify(); }
        catch (\Throwable $error) { $output->writeln('<error>Audit verification unavailable: '.$error->getMessage().'</error>'); return Command::INVALID; }
        if ($result['errors'] !== []) {
            foreach ($result['errors'] as $error) { $output->writeln('<error>'.$error.'</error>'); }
            return Command::FAILURE;
        }
        $output->writeln($input->getOption('checkpoint')
            ? \App\Audit\AuditCanonical::json(['version' => 1, 'recordedAt' => gmdate('c'), 'entries' => $result['count'], 'sequence' => $result['sequence'], 'hash' => $result['hash']])
            : sprintf('Verified %d entries; v2 sequence %d; head %s.', $result['count'], $result['sequence'], $result['hash'] ?? '(empty)'));
        return Command::SUCCESS;
    }
}
