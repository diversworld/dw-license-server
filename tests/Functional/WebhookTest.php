<?php
namespace App\Tests\Functional;
use App\Entity\{WebhookEndpoint, WebhookDelivery};
use App\Message\SendWebhook;
use App\MessageHandler\SendWebhookHandler;
use App\Security\{TotpSecretCipher, WebhookSignature};
use App\Service\{WebhookOutbox, WebhookTargets};
use App\Tests\Support\IsolatedWebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\{MockHttpClient, NoPrivateNetworkHttpClient};
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\{LockFactory, Store\FlockStore};
use Symfony\Component\Uid\Uuid;
final class WebhookTest extends IsolatedWebTestCase
{
    public function testSignedAsyncDeliveryIsUniqueAndReplayChecksRejectTamperingAndOldTimes(): void
    {
        $license = $this->license(); $directory = sys_get_temp_dir().'/webhook-key-'.bin2hex(random_bytes(8));
        $cipher = new TotpSecretCipher(new Filesystem(), new LockFactory(new FlockStore()), $directory.'/key', true);
        $secret = 'known-webhook-secret';
        $endpoint = new WebhookEndpoint($license->getCustomer(), 'https://webhook.example.org/events', $cipher->encrypt($secret), ['license.created']); $this->em->persist($endpoint); $this->em->flush();
        $eventId = Uuid::v7(); $outbox = static::getContainer()->get(WebhookOutbox::class); $outbox->publish('license.created', $license, $eventId); $outbox->publish('license.created', $license, $eventId);
        self::assertSame(1, $this->em->getRepository(WebhookDelivery::class)->count([]));
        $sent = [];
        $mock = new MockHttpClient(function ($method, $url, $options) use (&$sent): MockResponse { $sent[] = [$method, $url, $options]; return new MockResponse('', ['http_code' => 200, 'primary_ip' => '93.184.216.34']); });
        $http = (new NoPrivateNetworkHttpClient($mock))->withOptions(['resolve' => ['webhook.example.org' => '93.184.216.34']]);
        try {
            $handler = new SendWebhookHandler($this->em, $http, $cipher); $entry = $this->em->getRepository(WebhookDelivery::class)->findOneBy([]);
            $message = new SendWebhook((string) $entry->getId()); $handler($message); $handler($message); self::assertCount(1, $sent);
            $headers = []; foreach ($sent[0][2]['headers'] as $header) { [$name, $value] = explode(':', $header, 2); $headers[strtolower($name)] = trim($value); }
            $body = $sent[0][2]['body']; $id = $headers['x-license-event-id']; $time = $headers['x-license-timestamp']; $signature = $headers['x-license-signature'];
            self::assertTrue(WebhookSignature::verify($body, $id, $time, $signature, $secret, (int) $time));
            self::assertFalse(WebhookSignature::verify($body.' ', $id, $time, $signature, $secret, (int) $time));
            self::assertFalse(WebhookSignature::verify($body, $id, $time, $signature, $secret, (int) $time + 301));
            self::assertFalse(WebhookSignature::verify($body, (string) Uuid::v7(), $time, $signature, $secret, (int) $time));
            self::assertStringNotContainsString($license->getLicenseKey(), $body);
            self::assertStringNotContainsString($secret, json_encode($this->em->getConnection()->fetchAllAssociative('SELECT context FROM audit_log')));
            self::assertSame('sent', $entry->getStatus()); self::assertSame(1, $entry->getAttempts());
        } finally { (new Filesystem())->remove($directory); }
    }
    public function testPrivateResolvedAddressesAreBlockedAndFailuresHaveDurableHistory(): void
    {
        $license = $this->license(); $directory = sys_get_temp_dir().'/webhook-private-key-'.bin2hex(random_bytes(8));
        $cipher = new TotpSecretCipher(new Filesystem(), new LockFactory(new FlockStore()), $directory.'/key', true);
        $endpoint = new WebhookEndpoint($license->getCustomer(), 'https://private.example.org/events', $cipher->encrypt('private-test-secret'), ['license.created']); $this->em->persist($endpoint); $this->em->flush();
        static::getContainer()->get(WebhookOutbox::class)->publish('license.created', $license); $entry = $this->em->getRepository(WebhookDelivery::class)->findOneBy([]);
        $calls = 0; $mock = new MockHttpClient(function () use (&$calls): MockResponse { ++$calls; return new MockResponse('', ['http_code' => 200]); });
        $http = (new NoPrivateNetworkHttpClient($mock))->withOptions(['resolve' => ['private.example.org' => '127.0.0.1']]);
        try {
            $handler = new SendWebhookHandler($this->em, $http, $cipher);
            try { $handler(new SendWebhook((string) $entry->getId())); self::fail('Private target was contacted.'); } catch (\RuntimeException $error) { self::assertStringNotContainsString('private-test-secret', $error->getMessage()); }
            self::assertSame(0, $calls); $this->em->clear(); $entry = $this->em->find(WebhookDelivery::class, $entry->getId()); self::assertSame('retry', $entry->getStatus()); self::assertSame(1, $entry->getAttempts()); self::assertCount(1, $entry->getHistory());
        } finally { (new Filesystem())->remove($directory); }
    }
    public function testUnsafeUrlsAreRejectedBeforeDispatch(): void
    {
        foreach (['http://public.example.org/', 'https://127.0.0.1/', 'https://[::1]/', 'https://localhost/', 'https://public.example.org:8443/', 'https://user:secret@public.example.org/', 'https://public.example.org/?secret=x', 'https://public.example.org/#fragment'] as $url) {
            try { WebhookTargets::validate($url); self::fail('Unsafe URL accepted.'); } catch (\DomainException $error) { self::assertSame('webhook.invalid_url', $error->getMessage()); }
        }
        WebhookTargets::validate('https://public.example.org/events'); self::assertTrue(true);
    }
}
