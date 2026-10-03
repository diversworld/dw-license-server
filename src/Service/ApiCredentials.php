<?php
namespace App\Service;
use App\Entity\{ApiToken, Customer};
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
final class ApiCredentials
{
    public const array SCOPES = ['licenses:create', 'licenses:renew'];
    public function __construct(private readonly EntityManagerInterface $em, private readonly Security $security) {}
    /** @return array{credential: ApiToken, secret: string} Secret exists only in this creation response. */
    public function create(Customer $customer, string $name, array $scopes, \DateTimeImmutable $expiry): array
    {
        if (!$this->security->isGranted('API_CREDENTIAL_MANAGE', $customer)) { throw new AccessDeniedException(); }
        if (!$customer->isActive() || $scopes === [] || array_diff($scopes, self::SCOPES) !== [] || $expiry <= new \DateTimeImmutable() || $expiry > new \DateTimeImmutable('+1 year') || strlen(trim($name)) < 1 || strlen($name) > 255) { throw new \DomainException('api_credentials.invalid'); }
        $secret = 'dwapi_'.bin2hex(random_bytes(32));
        $credential = (new ApiToken())->setCustomer($customer)->setName(trim($name))->setToken($secret)->setScopes(array_values(array_unique($scopes)))->setExpiresAt($expiry)->setActive(true);
        $this->em->persist($credential); $this->em->flush();
        return ['credential' => $credential, 'secret' => $secret];
    }
    public function revoke(ApiToken $credential): void
    {
        if (!$this->security->isGranted('API_CREDENTIAL_MANAGE', $credential->getCustomer())) { throw new AccessDeniedException(); }
        $this->em->getConnection()->transactional(function () use ($credential): void { $this->em->refresh($credential, LockMode::PESSIMISTIC_WRITE); $credential->setActive(false)->setUpdatedAt(new \DateTimeImmutable()); $this->em->flush(); });
    }
}
