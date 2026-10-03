<?php
// Separate processes and connections exercise actual database locks, never an in-memory substitute.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$kernel = new \App\Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
if (!\Doctrine\DBAL\Types\Type::hasType('uuid')) { \Doctrine\DBAL\Types\Type::addType('uuid', \Symfony\Bridge\Doctrine\Types\UuidType::class); }
$parser = new \Doctrine\DBAL\Tools\DsnParser(['mysql' => 'pdo_mysql']);
$connection = \Doctrine\DBAL\DriverManager::getConnection($parser->parse(getenv('DATABASE_URL')));
$container->set('doctrine.dbal.default_connection', $connection);
fgets(STDIN);
$connection->transactional(function () use ($connection, $container): void {
    for ($i = 0; $i < 3; ++$i) {
        $container->get(\App\Service\AuditService::class)->log('concurrent.append', 'test', 'worker:'.getmypid(), null, 'Committed event '.$i);
        if ($i === 0) { usleep(300000); } // Retain only the lock obtained by the actual append operation.
    }
});
echo "COMMITTED\n";
