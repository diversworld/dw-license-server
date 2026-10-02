<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\License;
use App\Entity\LicenseAuditLog;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::postRemove)]
#[AsDoctrineListener(event: Events::postFlush)]
final class LicenseAuditSubscriber
{
    /**
     * @var LicenseAuditLog[]
     */
    private array $pendingLogs = [];

    private bool $processing = false;

    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $entity = $args->getObject();

        if (!$entity instanceof License) {
            return;
        }

        $this->queueLog(
            'license.created',
            sprintf(
                'Lizenz %s wurde erstellt',
                $entity->getLicenseKey()
            ),
            $entity
        );
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $entity = $args->getObject();

        if (!$entity instanceof License) {
            return;
        }

        $changeSet = $args
            ->getObjectManager()
            ->getUnitOfWork()
            ->getEntityChangeSet($entity);

        $context = [];

        foreach ($changeSet as $field => $values) {
            $context[$field] = [
                'old' => $this->normalizeValue($values[0]),
                'new' => $this->normalizeValue($values[1]),
            ];
        }

        $this->queueLog(
            'license.updated',
            sprintf(
                'Lizenz %s wurde geändert',
                $entity->getLicenseKey()
            ),
            $entity,
            $context
        );
    }

    public function postRemove(PostRemoveEventArgs $args): void
    {
        $entity = $args->getObject();

        if (!$entity instanceof License) {
            return;
        }

        $this->queueLog(
            'license.deleted',
            sprintf(
                'Lizenz %s wurde gelöscht',
                $entity->getLicenseKey()
            ),
            null,
            [
                'license_key' => $entity->getLicenseKey(),
            ]
        );
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->processing) {
            return;
        }

        if ([] === $this->pendingLogs) {
            return;
        }

        $this->processing = true;

        $entityManager = $args->getObjectManager();

        foreach ($this->pendingLogs as $log) {
            $entityManager->persist($log);
        }

        $this->pendingLogs = [];

        $entityManager->flush();

        $this->processing = false;
    }

    private function queueLog(
        string $eventType,
        string $message,
        ?License $license = null,
        array $context = [],
    ): void {
        $log = new LicenseAuditLog();

        $log->setEventType($eventType);
        $log->setMessage($message);
        $log->setLicense($license);
        $log->setPerformedBy($this->getCurrentUser());
        $log->setContext($context);
        $log->setIpAddress($this->getClientIp());
        $log->setUserAgent($this->getUserAgent());

        $this->pendingLogs[] = $log;
    }

    private function getCurrentUser(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User
            ? $user
            : null;
    }

    private function getClientIp(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();

        return $request?->getClientIp();
    }

    private function getUserAgent(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();

        return $request?->headers->get('User-Agent');
    }

    private function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if (is_object($value)) {
            if (method_exists($value, 'getId')) {
                return [
                    'class' => $value::class,
                    'id' => (string) $value->getId(),
                ];
            }

            return $value::class;
        }

        return $value;
    }
}