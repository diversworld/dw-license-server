<?php

namespace App\Command;

use App\Entity\Product;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:license:init', description: 'Registriert das erste Contao-Modul, ohne vorhandene Produkte zu überschreiben.')]
class InitializeProductsCommand extends Command
{
    public function __construct(private readonly ProductRepository $products, private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $slug = 'contao-issue-service-bundle';
        if (!$this->products->findOneBy(['slug' => $slug])) {
            $this->em->persist((new Product())->setAllowedFeatures(['sla'])->setRequiredFeatures(['sla'])->setSlug($slug)->setName('Contao Issue Service Bundle')->setDescription('diversworld/contao-issue-service-bundle; Premium-Feature: sla')->setActive(true));
            $this->em->flush();
        }
        $output->writeln('Contao Issue Service Bundle ist registriert.');

        return Command::SUCCESS;
    }
}
