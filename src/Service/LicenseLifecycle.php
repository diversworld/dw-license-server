<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\{License, LicenseAction, User};
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class LicenseLifecycle
{
    public const array ACTIONS = LicenseTransitions::ACTIONS;

    public function __construct(private readonly EntityManagerInterface $em, private readonly Security $security, private readonly ?WebhookOutbox $webhooks = null)
    {
    }

    public function perform(License $license, string $action, string $reason, ?\DateTimeImmutable $expiresAt = null): LicenseAction
    {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException('Unknown license action.');
        }
        $actor = $this->security->getUser();
        if (!$actor instanceof User || !$this->security->isGranted('LICENSE_'.strtoupper($action), $license)) {
            throw new AccessDeniedException();
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 1000) {
            throw new \DomainException('license_action.invalid_reason');
        }

        // The database row lock also serializes against concurrent activations.
        return $this->em->getConnection()->transactional(function () use ($license, $action, $reason, $expiresAt, $actor): LicenseAction {
            $this->em->refresh($license, LockMode::PESSIMISTIC_WRITE);
            if ($license->isArchived()) {
                throw new \DomainException('archive.must_restore');
            }
            $beforeStatus = $license->getStatus();
            $beforeExpiry = $license->getExpiresAt();
            $now = new \DateTimeImmutable();
            $target = LicenseTransitions::target($action, $beforeStatus, $license->isExpired());
            if ($action === 'renew') {
                if ($expiresAt === null || $expiresAt <= $now || ($beforeExpiry !== null && $expiresAt <= $beforeExpiry)) {
                    throw new \DomainException('license_action.invalid_expiry');
                }
                $license->setExpiresAt($expiresAt);
            } else {
                $license->setStatus($target);
            }
            $license->setUpdatedAt($now);
            $entry = new LicenseAction($license, $action, $reason, $actor, $beforeStatus, $license->getStatus(), $beforeExpiry, $license->getExpiresAt());
            $this->em->persist($entry);
            $this->em->flush();

            $this->webhooks?->publish($action === 'renew' ? 'license.renewed' : 'license.'.$action, $license);
            return $entry;
        });
    }
}
