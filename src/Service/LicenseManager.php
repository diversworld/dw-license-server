<?php

namespace App\Service;

use App\Dto\ActivationRequest;
use App\Dto\RefreshRequest;
use App\Entity\Activation;
use App\Entity\License;
use App\Entity\LogEntry;
use App\Repository\ActivationRepository;
use App\Repository\LicenseRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Uid\Uuid;

class LicenseManager
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LicenseRepository $licenses,
        private readonly ActivationRepository $activations,
        private readonly LicenseSigner $signer,
        private readonly LockFactory $lockFactory,
        private readonly ?\Symfony\Bundle\SecurityBundle\Security $security = null,
    ) {
    }

    public function activate(ActivationRequest $request): string
    {
        $license = $this->licenses->findOneBy(['licenseKey' => $request->licenseKey]);
        if (!$license || $license->getProduct()?->getSlug() !== $request->product) {
            throw new HttpException(403, 'Invalid license.');
        }
        $lock = $this->lockFactory->createLock('license.'.$license->getId(), 30);
        if (!$lock->acquire()) {
            throw new HttpException(503, 'License is busy. Please retry.');
        }
        try {
            return $this->em->getConnection()->transactional(function () use ($license, $request): string {
                // The database row lock also serializes requests across server instances.
                $this->em->refresh($license, LockMode::PESSIMISTIC_WRITE);
                $this->assertUsable($license);
                $domain = strtolower(rtrim($request->domain, '.'));
                $activation = $this->activations->findOneBy(['license' => $license, 'domain' => $domain]);
                if ($activation && $activation->getTenant() !== $request->tenant) {
                    throw new HttpException(403, 'Installation belongs to another tenant.');
                }
                if ($activation && !$activation->isActive()) {
                    throw new HttpException(403, 'Installation disabled.');
                }
                if (!$activation) {
                    if ($this->activations->count(['license' => $license, 'active' => true]) >= $license->getMaxDomains()) {
                        throw new HttpException(409, 'Installation limit reached.');
                    }
                    $activation = (new Activation())->setLicense($license)->setTenant($request->tenant)->setDomain($domain)->setActivatedAt(new \DateTimeImmutable());
                    $this->em->persist($activation);
                    $this->em->flush();
                    $this->audit('activated', $license, $activation);
                }

                $token = $this->issue($license, $activation);
                $this->em->flush();

                return $token;
            });
        } finally {
            $lock->release();
        }
    }

    public function refresh(RefreshRequest $request): string
    {
        try {
            $claims = $this->signer->verify($request->token);
        } catch (\DomainException) {
            throw new HttpException(401, 'Invalid token.');
        }
        if (($claims['tenant'] ?? null) !== $request->tenant || ($claims['domain'] ?? null) !== strtolower(rtrim($request->domain, '.'))
            || ($claims['mode'] ?? null) !== 'online' || !is_string($claims['license_id'] ?? null)
            || !Uuid::isValid($claims['license_id']) || !is_int($claims['activation_id'] ?? null)) {
            throw new HttpException(403, 'Invalid installation binding.');
        }
        $license = $this->licenses->find(Uuid::fromString($claims['license_id']));
        $activation = $this->activations->find($claims['activation_id']);
        if (!$license || !$activation || !$activation->isActive() || $activation->getLicense()?->getId()?->toRfc4122() !== $license->getId()?->toRfc4122()
            || $activation->getTenant() !== $request->tenant || $activation->getDomain() !== $claims['domain']
            || $license->getProduct()?->getSlug() !== ($claims['product'] ?? null) || 'online' !== $license->getMode()) {
            throw new HttpException(403, 'Installation disabled or unknown.');
        }
        $lock = $this->lockFactory->createLock('license.'.$license->getId(), 30);
        if (!$lock->acquire()) {
            throw new HttpException(503, 'License is busy. Please retry.');
        }
        try {
            return $this->em->getConnection()->transactional(function () use ($license, $activation): string {
                $this->em->refresh($license, LockMode::PESSIMISTIC_WRITE);
                $this->em->refresh($activation, LockMode::PESSIMISTIC_WRITE);
                if (!$activation->isActive()) {
                    throw new HttpException(403, 'Installation disabled.');
                }
                $this->assertUsable($license);
                $token = $this->issue($license, $activation);
                $this->audit('refreshed', $license, $activation);
                $this->em->flush();

                return $token;
            });
        } finally {
            $lock->release();
        }
    }

    private function assertUsable(License $license): void
    {
        $actor = $this->security?->getUser();
        if ($actor instanceof \App\Entity\User && !\App\Security\CustomerAccess::allows($actor, $license)) { throw new HttpException(403, 'Invalid license.'); }
        if ($license->isExpired()) {
            throw new HttpException(410, 'License expired.');
        }
        if (!$license->isActive() || !$license->getCustomer()?->isActive() || !$license->getProduct()?->isActive()) {
            throw new HttpException(403, 'License disabled.');
        }
        if ('contao-issue-service-bundle' === $license->getProduct()?->getSlug() && !$license->hasFeature('sla')) {
            throw new HttpException(422, 'Für das Contao Issue Service Bundle muss die Lizenz das Feature sla enthalten. Bitte die Lizenz bearbeiten und sla hinzufügen.');
        }
        if ('offline' === $license->getMode() && null === $license->getExpiresAt()) {
            throw new HttpException(422, 'Offline licenses require an expiry date.');
        }
    }

    private function issue(License $license, Activation $activation): string
    {
        $now = new \DateTimeImmutable();
        $product = $license->getProduct();
        $refresh = $now->getTimestamp() + $product->getTokenLifetimeSeconds();
        $graceUntil = $refresh + $product->getGracePeriodSeconds();
        $expires = 'offline' === $license->getMode()
            ? $license->getExpiresAt()->getTimestamp()
            : min($license->getExpiresAt()?->getTimestamp() ?? PHP_INT_MAX, $graceUntil);
        $claims = [
            'license_id' => $license->getId()->toRfc4122(),
            'activation_id' => $activation->getId(),
            'product' => $license->getProduct()->getSlug(),
            'tenant' => $activation->getTenant(),
            'domain' => $activation->getDomain(),
            'issued_at' => $now->getTimestamp(),
            'expires_at' => $expires,
            'mode' => $license->getMode(),
            'refresh_after' => min($expires, $refresh),
            'grace_until' => min($expires, $graceUntil),
            'status' => 'valid',
            'features' => $license->getFeatures(),
        ];
        $token = $this->signer->sign($claims);
        $license->setLastValidationAt($now);
        $activation->setUpdatedAt($now);

        return $token;
    }

    private function audit(string $action, License $license, Activation $activation): void
    {
        $this->em->persist((new LogEntry())->setAction($action)->setMessage(sprintf('License %s, installation %d', $license->getId(), $activation->getId())));
    }
}
