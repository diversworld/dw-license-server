<?php
namespace App\Service;
use App\Entity\{License, WebhookDelivery, WebhookEndpoint};
use App\Message\SendWebhook;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;
final class WebhookOutbox
{
    public const array EVENTS = ['license.created', 'license.renewed', 'license.pause', 'license.revoke', 'license.reactivate', 'license.withdraw_revocation', 'license.domain_change'];
    public function __construct(private readonly EntityManagerInterface $em, private readonly MessageBusInterface $bus) {}
    public function publish(string $event, License $license, ?Uuid $eventId = null): void
    {
        if (!in_array($event, self::EVENTS, true)) { throw new \InvalidArgumentException('Unsupported webhook event.'); }
        $eventId ??= Uuid::v7();
        foreach ($this->em->getRepository(WebhookEndpoint::class)->findBy(['customer' => $license->getCustomer(), 'active' => true]) as $endpoint) {
            if (!in_array($event, $endpoint->getEvents(), true) || $this->em->getRepository(WebhookDelivery::class)->findOneBy(['endpoint' => $endpoint, 'eventId' => $eventId]) !== null) { continue; }
            $payload = ['version' => 1, 'id' => (string) $eventId, 'type' => $event, 'occurredAt' => gmdate(DATE_ATOM), 'data' => ['customerId' => (string) $license->getCustomer()->getId(), 'licenseId' => (string) $license->getId(), 'expiresAt' => $license->getExpiresAt()?->format(DATE_ATOM), 'status' => $license->getStatus(), 'features' => $license->getFeatures()]];
            $delivery = new WebhookDelivery($endpoint, $eventId, $payload); $this->em->persist($delivery); $this->em->flush();
            $this->bus->dispatch(new SendWebhook((string) $delivery->getId()));
        }
    }
}
