<?php

namespace App\Service;

use App\Archive\ArchivableInterface;
use App\Entity\User;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class ArchiveService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly AuditService $audit,
    ) {
    }

    public function change(ArchivableInterface $entity, bool $restore, string $reason): void
    {
        $permission = $restore ? 'RECORD_RESTORE' : 'RECORD_ARCHIVE';
        $actor = $this->security->getUser();
        if (!$actor instanceof User || !$this->security->isGranted($permission, $entity)) {
            throw new AccessDeniedException();
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 1000) {
            throw new \DomainException('license_action.invalid_reason');
        }
        $this->em->getConnection()->transactional(function () use ($entity, $restore, $reason, $actor): void {
            $this->em->refresh($entity, LockMode::PESSIMISTIC_WRITE);
            if ($entity->isArchived() !== $restore) {
                throw new \DomainException('archive.invalid_transition');
            }
            $restore ? $entity->restore() : $entity->archive();
            $entity->setUpdatedAt(new \DateTimeImmutable());
            $this->em->flush();
            $type = $this->em->getClassMetadata($entity::class)->getTableName();
            $id = (string) $entity->getId();
            $action = $restore ? 'restored' : 'archived';
            $this->audit->log($type.'.'.$action, $type, $type.':'.$id, $id, $reason, $actor, ['reason' => $reason, 'archived' => !$restore]);
        });
    }
}
