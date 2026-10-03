<?php
namespace App\Command;
use App\Service\ExpiryReminders;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
#[AsCommand(name: 'app:licenses:remind', description: 'Queue configured expiry reminders without synchronous delivery.')]
final class ScheduleExpiryRemindersCommand extends Command
{
    public function __construct(private readonly ExpiryReminders $reminders) { parent::__construct(); }
    protected function execute(InputInterface $input, OutputInterface $output): int { $output->writeln('Queued '.$this->reminders->schedule(new \DateTimeImmutable()).' reminders.'); return Command::SUCCESS; }
}
