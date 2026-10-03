<?php

declare(strict_types=1);

namespace App\Service;

use App\Audit\AuditCanonical;
use App\Entity\AuditLog;
use Doctrine\ORM\EntityManagerInterface;

final class AuditIntegrity
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    /** @return array{count: int, sequence: int, hash: ?string, errors: list<string>} */
    public function verify(): array
    {
        return $this->em->getConnection()->transactional(fn () => $this->verifyLocked());
    }

    private function verifyLocked(): array
    {
        $connection = $this->em->getConnection();
        $queryHead = $connection->createQueryBuilder()->select('sequence', 'entry_hash')->from('audit_chain_head')->where('id = 1');
        if (!$connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\SQLitePlatform) { $queryHead->forUpdate(); }
        $head = $queryHead->executeQuery()->fetchAssociative();
        $previous = null;
        $sequence = $count = 0;
        $errors = [];
        // Legacy order matches the original append algorithm; v2 sequence is authoritative.
        $query = $this->em->createQuery('SELECT a FROM App\Entity\AuditLog a ORDER BY a.hashVersion ASC, a.chainSequence ASC, a.createdAt ASC, a.id ASC');
        foreach ($query->toIterable() as $log) {
            ++$count;
            $id = (string) $log->getId();
            if ($log->getPreviousHash() !== $previous) { $errors[] = "Predecessor mismatch at $id"; }
            if ($log->getHashVersion() === 2 && $log->getChainSequence() !== ++$sequence) { $errors[] = "Sequence gap at $id"; }
            try {
                if (!hash_equals($log->getEntryHash(), AuditCanonical::hash($log))) { $errors[] = "Content hash mismatch (v{$log->getHashVersion()}) at $id"; }
            } catch (\UnexpectedValueException $error) { $errors[] = "$id: {$error->getMessage()}"; }
            $previous = $log->getEntryHash();
            $this->em->detach($log);
        }
        if ($head !== false && ((int) $head['sequence'] !== $sequence || $head['entry_hash'] !== $previous)) { $errors[] = 'Stored chain head does not match the final entry.'; }
        if ($count > 0 && $head === false) { $errors[] = 'Missing chain head.'; }

        return ['count' => $count, 'sequence' => $sequence, 'hash' => $previous, 'errors' => $errors];
    }
}
