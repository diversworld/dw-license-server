<?php
namespace App\MessageHandler;
use App\Entity\WebhookDelivery;
use App\Message\SendWebhook;
use App\Security\{TotpSecretCipher, WebhookSignature};
use App\Service\WebhookTargets;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\HttpClientInterface;
#[AsMessageHandler]
final class SendWebhookHandler
{
    private readonly HttpClientInterface $http;
    public function __construct(private readonly EntityManagerInterface $em, HttpClientInterface $http, private readonly TotpSecretCipher $cipher)
    { $this->http = $http instanceof NoPrivateNetworkHttpClient ? $http : new NoPrivateNetworkHttpClient($http); }
    public function __invoke(SendWebhook $message): void
    {
        $failure = null;
        $this->em->getConnection()->transactional(function () use ($message, &$failure): void {
            $entry = $this->em->find(WebhookDelivery::class, Uuid::fromString($message->deliveryId)); if ($entry === null) { return; }
            $this->em->refresh($entry, LockMode::PESSIMISTIC_WRITE);
            if (in_array($entry->getStatus(), ['sent', 'cancelled'], true)) { return; }
            $endpoint = $entry->getEndpoint(); $this->em->refresh($endpoint);
            if (!$endpoint->isActive() || !$endpoint->getCustomer()->isActive()) { $entry->record('cancelled'); $this->em->flush(); return; }
            $status = null;
            try {
                WebhookTargets::validate($endpoint->getUrl());
                $body = json_encode($entry->getPayload(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); $timestamp = time(); $id = (string) $entry->getEventId();
                $response = $this->http->request('POST', $endpoint->getUrl(), ['body' => $body, 'headers' => ['Content-Type' => 'application/json', 'X-License-Event-ID' => $id, 'X-License-Timestamp' => (string) $timestamp, 'X-License-Signature' => WebhookSignature::sign($body, $id, $timestamp, $this->cipher->decrypt($endpoint->getSecretCiphertext()))], 'max_redirects' => 0, 'timeout' => 5, 'max_duration' => 10, 'verify_peer' => true, 'verify_host' => true, 'proxy' => '']);
                $status = $response->getStatusCode(); $response->cancel();
                if ($status < 200 || $status >= 300) { throw new \RuntimeException('Webhook HTTP response rejected.'); }
                $entry->record('sent', $status);
            } catch (\Throwable $error) { $entry->record('retry', $status, $error::class); $failure = new \RuntimeException('Webhook delivery failed; retry its stable event identifier.'); }
            $this->em->flush();
        });
        if ($failure !== null) { throw $failure; }
    }
}
