<?php
// A subprocess using its own production container and database connection; only isolated tests invoke it.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$kernel = new \App\Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
if (!\Doctrine\DBAL\Types\Type::hasType('uuid')) { \Doctrine\DBAL\Types\Type::addType('uuid', \Symfony\Bridge\Doctrine\Types\UuidType::class); }
$parser = new \Doctrine\DBAL\Tools\DsnParser(['mysql' => 'pdo_mysql']);
$container->set('doctrine.dbal.default_connection', \Doctrine\DBAL\DriverManager::getConnection($parser->parse(getenv('DATABASE_URL'))));
$em = $container->get(\Doctrine\ORM\EntityManagerInterface::class);
$user = $em->find(\App\Entity\User::class, \Symfony\Component\Uid\Uuid::fromString($argv[2]));
$license = $em->find(\App\Entity\License::class, \Symfony\Component\Uid\Uuid::fromString($argv[1]));
$token = new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken($user, 'main', $user->getRoles());
$container->get('security.token_storage')->setToken($token);
$security = $container->get(\Symfony\Bundle\SecurityBundle\Security::class);
$lifecycle = new \App\Service\LicenseLifecycle($em, $security);
fwrite(STDOUT, "READY\n");
fflush(STDOUT);
fgets(STDIN);
try {
    $em->getConnection()->beginTransaction();
    $em->refresh($license, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
    // Force overlap so the other connection must wait for the same row lock.
    usleep(500000);
    $lifecycle->perform($license, 'pause', 'Concurrent customer request');
    $em->getConnection()->commit();
    echo "SUCCESS\n";
} catch (\DomainException $e) {
    if ($em->getConnection()->isTransactionActive()) { $em->getConnection()->rollBack(); }
    echo "REJECTED\n";
    exit(2);
} catch (\Throwable $e) {
    if ($em->getConnection()->isTransactionActive()) { $em->getConnection()->rollBack(); }
    fwrite(STDERR, $e::class.': '.$e->getMessage());
    exit(3);
}
