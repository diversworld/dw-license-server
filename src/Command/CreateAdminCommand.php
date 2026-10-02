<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:admin:create', description: 'Legt einen Administrator an; Passwort wird verdeckt abgefragt.')]
class CreateAdminCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly UserRepository $users, private readonly UserPasswordHasherInterface $hasher)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('role', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'admin, support, sales oder viewer', 'admin');
        $this->addArgument('email', InputArgument::REQUIRED)->addArgument('firstname', InputArgument::OPTIONAL, '', 'Admin')->addArgument('lastname', InputArgument::OPTIONAL, '', '');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $role = strtoupper($input->getOption('role'));
        if (!in_array($role, ['ADMIN', 'SUPPORT', 'SALES', 'VIEWER'], true)) {
            $io->error('Unbekannte Rolle.');
            return Command::FAILURE;
        }
        $email = strtolower(trim($input->getArgument('email')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $this->users->findOneBy(['email' => $email])) {
            $io->error('E-Mail ungültig oder Benutzer existiert bereits.');

            return Command::FAILURE;
        }
        if (!$input->isInteractive()) {
            $io->error('Dieses Kommando benötigt eine interaktive Passwortabfrage.');

            return Command::FAILURE;
        }
        $password = $io->askHidden('Passwort (mindestens 12 Zeichen)', function (?string $value): string {
            if (null === $value || strlen($value) < 12) {
                throw new \InvalidArgumentException('Mindestens 12 Zeichen erforderlich.');
            }

            return $value;
        });
        $user = (new User())->setEmail($email)->setFirstname($input->getArgument('firstname'))->setLastname($input->getArgument('lastname'))->setRoles(['ROLE_'.$role]);
        $user->setPassword($this->hasher->hashPassword($user, $password));
        $this->em->persist($user);
        $this->em->flush();
        $io->success('Benutzer angelegt; TOTP wird bei der ersten Anmeldung eingerichtet.');

        return Command::SUCCESS;
    }
}
