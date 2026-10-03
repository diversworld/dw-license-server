<?php
namespace App\Service;
use App\Entity\{Customer, WebhookEndpoint};
use App\Security\TotpSecretCipher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
final class WebhookRegistration
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly Security $security, private readonly TotpSecretCipher $cipher) {}
    public function create(Customer $customer, string $url, array $events): array
    {
        if (!$this->security->isGranted('API_CREDENTIAL_MANAGE', $customer)) { throw new AccessDeniedException(); }
        WebhookTargets::validate($url);
        if (!$customer->isActive() || $events === [] || array_diff($events, WebhookOutbox::EVENTS) !== []) { throw new \DomainException('webhook.invalid_events'); }
        $secret = bin2hex(random_bytes(32));
        $endpoint = new WebhookEndpoint($customer, $url, $this->cipher->encrypt($secret), array_values(array_unique($events)));
        $this->em->persist($endpoint); $this->em->flush(); return ['endpoint' => $endpoint, 'secret' => $secret];
    }
    public function disable(WebhookEndpoint $endpoint): void
    {
        if (!$this->security->isGranted('API_CREDENTIAL_MANAGE', $endpoint->getCustomer())) { throw new AccessDeniedException(); }
        $endpoint->setActive(false); $this->em->flush();
    }
}
