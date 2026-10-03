<?php
namespace App\Service;
use App\Audit\AuditCanonical;
use App\Dto\{ManagementCreateRequest, ManagementRenewRequest};
use App\Entity\{ApiOperation, ApiToken, Customer, License, LicenseAction, Product};
use App\Security\ApiPrincipal;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
final class ApiLicenseManagement
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly WebhookOutbox $webhooks) {}
    public function create(ApiPrincipal $actor, ManagementCreateRequest $request, string $key): array
    {
        return $this->operation($actor, 'licenses:create', $key, ['create', (array) $request], function (ApiToken $credential, Customer $customer) use ($request): array {
            $product = $this->em->getRepository(Product::class)->findOneBy(['slug' => $request->product]);
            $expiry = new \DateTimeImmutable($request->expiresAt);
            if (!$product || !$product->isActive() || $expiry <= new \DateTimeImmutable()) { throw new HttpException(422, 'Invalid product or expiry.'); }
            $license = (new License())->setCustomer($customer)->setProduct($product)->setMode($request->mode)->setFeatures($request->features)->setMaxDomains($request->maxDomains)->setExpiresAt($expiry)->setNotes($request->reason);
            $this->em->persist($license); $this->em->flush();
            return ['license' => $license, 'event' => 'license.created', 'response' => ['licenseId' => (string) $license->getId(), 'licenseKey' => $license->getLicenseKey(), 'expiresAt' => $expiry->format(DATE_ATOM)]];
        });
    }
    public function renew(ApiPrincipal $actor, License $license, ManagementRenewRequest $request, string $key): array
    {
        if ((string) $license->getCustomer()?->getId() !== (string) $actor->customerId) { throw new HttpException(403, 'License belongs to another customer.'); }
        return $this->operation($actor, 'licenses:renew', $key, ['renew', (string) $license->getId(), (array) $request], function (ApiToken $credential, Customer $customer) use ($license, $request): array {
            $this->em->refresh($license, LockMode::PESSIMISTIC_WRITE);
            if ((string) $license->getCustomer()?->getId() !== (string) $customer->getId() || $license->isArchived()) { throw new HttpException(403, 'License unavailable.'); }
            $expiry = new \DateTimeImmutable($request->expiresAt); $before = $license->getExpiresAt();
            LicenseTransitions::target('renew', $license->getStatus(), $license->isExpired());
            if ($expiry <= new \DateTimeImmutable() || ($before !== null && $expiry <= $before)) { throw new HttpException(422, 'Expiry must extend the existing license.'); }
            $license->setExpiresAt($expiry)->setUpdatedAt(new \DateTimeImmutable());
            $entry = (new LicenseAction($license, 'renew', $request->reason, null, $license->getStatus(), $license->getStatus(), $before, $expiry))->setDetails(['apiCredentialId' => (string) $credential->getId()]);
            $this->em->persist($entry); $this->em->flush();
            return ['license' => $license, 'event' => 'license.renewed', 'response' => ['licenseId' => (string) $license->getId(), 'expiresAt' => $expiry->format(DATE_ATOM), 'status' => $license->getStatus()]];
        });
    }
    private function operation(ApiPrincipal $actor, string $scope, string $key, array $body, callable $change): array
    {
        if (!preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $key)) { throw new HttpException(400, 'A bounded Idempotency-Key header is required.'); }
        $hash = hash('sha256', AuditCanonical::json($body));
        return $this->em->getConnection()->transactional(function () use ($actor, $scope, $key, $hash, $change): array {
            $customer = $this->em->find(Customer::class, $actor->customerId);
            if (!$customer) { throw new HttpException(403, 'Customer unavailable.'); }
            $this->em->refresh($customer, LockMode::PESSIMISTIC_WRITE);
            $credential = $this->em->find(ApiToken::class, $actor->credentialId);
            if (!$credential) { throw new HttpException(403, 'Credential unavailable.'); }
            $this->em->refresh($credential, LockMode::PESSIMISTIC_WRITE);
            if (!$customer->isActive() || !$credential->isActive() || $credential->isExpired() || (string) $credential->getCustomer()?->getId() !== (string) $customer->getId() || !in_array($scope, $credential->getScopes(), true)) { throw new HttpException(403, 'Credential scope denied.'); }
            $previous = $this->em->getRepository(ApiOperation::class)->findOneBy(['credential' => $credential, 'idempotencyKey' => $key]);
            if ($previous !== null) {
                if (!hash_equals($previous->getBodyHash(), $hash)) { throw new HttpException(409, 'Idempotency-Key was already used for another request.'); }
                return $previous->getResponse();
            }
            $result = $change($credential, $customer);
            $operation = new ApiOperation($credential, $key, $hash, $result['response']); $this->em->persist($operation); $this->em->flush();
            $this->webhooks->publish($result['event'], $result['license'], $operation->getId());
            return $result['response'];
        });
    }
}
