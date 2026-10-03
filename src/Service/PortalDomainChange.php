<?php
namespace App\Service;
use App\Entity\{Activation, LicenseAction, User};
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Validator\{Constraints as Assert, Validator\ValidatorInterface};
final class PortalDomainChange
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly Security $security, private readonly ValidatorInterface $validator,
        #[Autowire('%env(int:PORTAL_DOMAIN_CHANGE_LIMIT)%')] private readonly int $limit,
        #[Autowire('%env(int:PORTAL_DOMAIN_CHANGE_WINDOW_DAYS)%')] private readonly int $windowDays) {
        if ($limit < 1 || $limit > 100 || $windowDays < 1 || $windowDays > 365) { throw new \InvalidArgumentException('Invalid domain change limits.'); }
    }
    public function change(Activation $activation, string $domain, string $reason): LicenseAction
    {
        $license = $activation->getLicense(); $actor = $this->security->getUser();
        if (!$license || !$actor instanceof User || !$this->security->isGranted('PORTAL_DOMAIN_CHANGE', $license)) { throw new AccessDeniedException(); }
        $domain = strtolower(rtrim(trim($domain), '.')); $reason = trim($reason);
        if (count($this->validator->validate($domain, [new Assert\Hostname(requireTld: true), new Assert\NotBlank(), new Assert\Length(max: 253)])) > 0 || mb_strlen($reason) < 3 || mb_strlen($reason) > 1000) { throw new \DomainException('portal.invalid_change'); }
        return $this->em->getConnection()->transactional(function () use ($activation, $license, $actor, $domain, $reason): LicenseAction {
            $this->em->refresh($license, LockMode::PESSIMISTIC_WRITE); $this->em->refresh($activation, LockMode::PESSIMISTIC_WRITE);
            if (!$activation->isActive() || (string) $activation->getLicense()?->getId() !== (string) $license->getId() || !$license->isActive() || $license->isExpired() || !$license->getCustomer()?->isActive() || !$license->getProduct()?->isActive()) { throw new \DomainException('portal.license_unusable'); }
            if ($domain === $activation->getDomain()) { throw new \DomainException('portal.domain_unchanged'); }
            $query = $this->em->getRepository(LicenseAction::class)->createQueryBuilder('a')->select('COUNT(a.id)')->andWhere('a.license = :license AND a.action = :action AND a.createdAt >= :after')->setParameter('license', $license->getId(), 'uuid')->setParameter('action', 'domain_change')->setParameter('after', new \DateTimeImmutable('-'.$this->windowDays.' days'))->getQuery();
            $changes = $query->getSingleScalarResult();
            if ((int) $changes >= $this->limit) { throw new \DomainException('portal.domain_limit'); }
            if ($this->em->getRepository(Activation::class)->findOneBy(['license' => $license, 'domain' => $domain])) { throw new \DomainException('portal.domain_in_use'); }
            $old = $activation->getDomain(); $activation->setDomain($domain)->setUpdatedAt(new \DateTimeImmutable());
            $entry = (new LicenseAction($license, 'domain_change', $reason, $actor, $license->getStatus(), $license->getStatus(), $license->getExpiresAt(), $license->getExpiresAt()))->setDetails(['activationId' => $activation->getId(), 'fromDomain' => $old, 'toDomain' => $domain]);
            $this->em->persist($entry); $this->em->flush(); return $entry;
        });
    }
}
