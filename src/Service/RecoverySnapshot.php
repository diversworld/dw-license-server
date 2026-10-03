<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\{Filesystem, Path};
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Process\{Process, ExecutableFinder};

final class RecoverySnapshot
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Filesystem $filesystem,
        private readonly LockFactory $locks,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDirectory,
        #[Autowire('%kernel.project_dir%/config/license')] private readonly string $keyDirectory,
        #[Autowire('%env(resolve:TOTP_ENCRYPTION_KEY_FILE)%')] private readonly string $totpKey,
    ) {}

    public function backup(string $destination, bool $maintenanceConfirmed): void
    {
        if (!$maintenanceConfirmed) { throw new \DomainException('Pause application writes and workers, then confirm maintenance.'); }
        $destination = Path::makeAbsolute($destination, getcwd());
        $parent = realpath(dirname($destination)) ?: throw new \DomainException('Create the private backup parent directory first.');
        $destination = $parent.'/'.basename($destination);
        if (file_exists($destination) || Path::isBasePath(realpath($this->projectDirectory) ?: $this->projectDirectory, $destination)) { throw new \DomainException('Use a new private directory outside the application tree.'); }
        $lock = $this->locks->createLock('license.signing-key-rotation', 3600);
        if (!$lock->acquire()) { throw new \RuntimeException('Signing state is busy.'); }
        $mask = umask(0077);
        try {
            $this->filesystem->mkdir($destination, 0700);
            $file = fopen($destination.'/database.sql', 'xb');
            if ($file === false) { throw new \RuntimeException('Cannot create SQL snapshot.'); }
            try {
                $process = $this->databaseProcess(true);
                $process->run(static function (string $type, string $chunk) use ($file, $process): void {
                    if ($type === Process::OUT && fwrite($file, $chunk) !== strlen($chunk)) { throw new \RuntimeException('Snapshot disk write failed.'); }
                    $process->clearOutput(); $process->clearErrorOutput();
                });
                if (!$process->isSuccessful()) { throw new \RuntimeException('Database dump failed.'); }
            } finally { fclose($file); }
            $this->copyTree($this->keyDirectory, $destination.'/license');
            foreach (['.env', '.env.local', '.env.prod.local', '.env.local.php'] as $name) {
                if (is_file($this->projectDirectory.'/'.$name)) { $this->filesystem->copy($this->projectDirectory.'/'.$name, $destination.'/configuration/'.$name); }
            }
            $this->copyTree($this->projectDirectory.'/config/packages', $destination.'/configuration/packages');
            if (is_file($this->totpKey)) { $this->filesystem->copy($this->totpKey, $destination.'/security/totp.key'); }
            elseif ((int) $this->connection->fetchOne("SELECT COUNT(*) FROM user WHERE totp_secret LIKE 'enc:v1:%'") > 0 || ($this->connection->createSchemaManager()->tablesExist(['webhook_endpoint']) && (int) $this->connection->fetchOne("SELECT COUNT(*) FROM webhook_endpoint WHERE secret_ciphertext LIKE 'enc:v1:%'") > 0)) { throw new \RuntimeException('Restore the independent authenticator key before taking a recovery snapshot.'); }
            $hashes = [];
            foreach ($this->files($destination) as $relative) { $hashes[$relative] = hash_file('sha256', $destination.'/'.$relative); }
            ksort($hashes);
            $this->filesystem->dumpFile($destination.'/manifest.json', json_encode(['version' => 1, 'createdAt' => gmdate(DATE_ATOM), 'databasePlatform' => $this->connection->getDatabasePlatform()::class, 'files' => $hashes], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            foreach ($this->files($destination) as $relative) { $this->filesystem->chmod($destination.'/'.$relative, 0600); }
        } catch (\Throwable $error) {
            $this->filesystem->remove($destination);
            throw $error;
        } finally { umask($mask); $lock->release(); }
    }

    /** Restore only into an explicitly empty database and application key/config directory. */
    public function restore(string $source): void
    {
        $source = realpath($source) ?: throw new \DomainException('Snapshot not found.');
        $manifest = json_decode(file_get_contents($source.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        if (($manifest['version'] ?? null) !== 1 || !is_array($manifest['files'] ?? null)) { throw new \DomainException('Invalid snapshot manifest.'); }
        $actual = array_values(array_filter($this->files($source), static fn (string $name): bool => $name !== 'manifest.json'));
        $expected = array_keys($manifest['files']); sort($actual); sort($expected);
        if ($actual !== $expected) { throw new \DomainException('Snapshot has missing or unlisted files.'); }
        foreach ($manifest['files'] as $relative => $hash) {
            if (!is_string($relative) || str_contains($relative, '..') || Path::isAbsolute($relative) || !is_file($source.'/'.$relative) || is_link($source.'/'.$relative) || !hash_equals($hash, hash_file('sha256', $source.'/'.$relative))) { throw new \DomainException('Snapshot integrity mismatch.'); }
        }
        if (!isset($manifest['files']['database.sql']) || $this->connection->createSchemaManager()->listTableNames() !== [] || is_dir($this->keyDirectory) || is_file($this->totpKey)) { throw new \DomainException('Restore requires an empty database and unused key destinations.'); }
        // Configuration is restored separately for review; never switch DATABASE_URL implicitly.
        if (file_exists($this->projectDirectory.'/recovered-configuration')) { throw new \DomainException('Configuration destination already exists.'); }
        $process = $this->databaseProcess(false); $stream = fopen($source.'/database.sql', 'rb');
        try {
            $process->setInput($stream); $process->disableOutput(); $process->run();
            if (!$process->isSuccessful()) { throw new \RuntimeException('SQL restore failed; discard the partial target and use a fresh empty database.'); }
        } finally { if (is_resource($stream)) { fclose($stream); } }
        $mask = umask(0077);
        try {
            $this->copyTree($source.'/license', $this->keyDirectory);
            $this->copyTree($source.'/configuration', $this->projectDirectory.'/recovered-configuration');
            if (is_file($source.'/security/totp.key')) { $this->filesystem->copy($source.'/security/totp.key', $this->totpKey); $this->filesystem->chmod($this->totpKey, 0600); }
        } finally { umask($mask); }
    }

    private function databaseProcess(bool $dump): Process
    {
        $parameters = $this->connection->getParams();
        if (!in_array($parameters['driver'] ?? null, ['pdo_mysql', 'mysqli'], true)) { throw new \DomainException('Recovery snapshots support MySQL/MariaDB.'); }
        $finder = new ExecutableFinder();
        $executable = $finder->find($dump ? 'mariadb-dump' : 'mariadb') ?? $finder->find($dump ? 'mysqldump' : 'mysql');
        if ($executable === null) { throw new \RuntimeException('MySQL/MariaDB client utilities are required.'); }
        $args = [$executable, '--host='.($parameters['host'] ?? 'localhost'), '--port='.($parameters['port'] ?? 3306), '--user='.($parameters['user'] ?? '')];
        if ($dump) { array_push($args, '--single-transaction', '--hex-blob', '--routines', '--triggers', '--events', '--no-tablespaces'); }
        $args[] = $parameters['dbname'];
        $process = new Process($args, env: ['MYSQL_PWD' => $parameters['password'] ?? '']);
        $process->setTimeout(3600);
        return $process;
    }

    private function files(string $directory): array
    {
        if (!is_dir($directory)) { throw new \DomainException('Required recovery directory is missing.'); }
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isLink()) { throw new \DomainException('Recovery trees must contain regular files, not symlinks.'); }
            if ($file->isFile()) { $files[] = substr($file->getPathname(), strlen($directory) + 1); }
        }
        return $files;
    }
    private function copyTree(string $source, string $destination): void
    {
        $this->filesystem->mkdir($destination, 0700);
        foreach ($this->files($source) as $relative) { $this->filesystem->copy($source.'/'.$relative, $destination.'/'.$relative); $this->filesystem->chmod($destination.'/'.$relative, 0600); }
    }
}
