<?php

namespace App\Tests\Support;

use App\Entity\User;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\{KernelBrowser, Test\WebTestCase};

abstract class IsolatedWebTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        if (!\Doctrine\DBAL\Types\Type::hasType('uuid')) {
            \Doctrine\DBAL\Types\Type::addType('uuid', \Symfony\Bridge\Doctrine\Types\UuidType::class);
        }
        static::getContainer()->set('doctrine.dbal.default_connection', DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
    }

    protected function user(string $role = 'ROLE_ADMIN'): User
    {
        $user = (new User())->setEmail(bin2hex(random_bytes(8)).'@example.test')->setFirstname('Test')->setLastname('User')->setRoles([$role])->setPassword('unused-hash');
        $user->declineTwoFactor();
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
