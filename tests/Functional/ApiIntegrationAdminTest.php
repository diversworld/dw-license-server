<?php
namespace App\Tests\Functional;
use App\Entity\{ApiToken, WebhookEndpoint};
use App\Tests\Support\IsolatedWebTestCase;
final class ApiIntegrationAdminTest extends IsolatedWebTestCase
{
    public function testSecretsAreDisplayedOnceAndRevocationRequiresCsrf(): void
    {
        $license = $this->license(); $admin = $this->user(); $this->client->loginUser($admin);
        $url = '/admin/de/customers/'.$license->getCustomer()->getId().'/api-credentials';
        $crawler = $this->client->request('GET', $url); self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Erstellen')->form();
        $this->client->submit($form, ['form[name]' => 'Integration', 'form[scopes]' => ['licenses:create'], 'form[expiry]' => (new \DateTimeImmutable('+1 month'))->format('Y-m-d\TH:i')]);
        self::assertResponseIsSuccessful(); preg_match('/dwapi_[a-f0-9]{64}/', $this->client->getResponse()->getContent(), $match); self::assertCount(1, $match);
        $secret = $match[0]; $credential = $this->em->getRepository(ApiToken::class)->findOneBy([]); self::assertNull($credential->getToken()); self::assertSame(hash('sha256', $secret), $credential->getTokenHash());
        $crawler = $this->client->request('GET', $url); self::assertStringNotContainsString($secret, $this->client->getResponse()->getContent());
        $form = $crawler->selectButton('Widerrufen')->form(); $revoke = $form->getUri();
        $this->client->request('POST', $revoke, ['_token' => 'forged']); self::assertResponseStatusCodeSame(403);
        $this->client->submit($form); self::assertResponseRedirects();
        $this->em->clear(); self::assertFalse($this->em->find(ApiToken::class, $credential->getId())->isActive());
    }
    public function testScopedAdminCannotManageForeignCredentialsAndWebhookSecretsAreEncrypted(): void
    {
        $own = $this->license(); $own->getProduct()->setSlug('own'); $this->em->flush(); $foreign = $this->license();
        $admin = $this->user()->setGlobalAccess(false)->addCustomer($own->getCustomer()); $this->em->flush(); $this->client->loginUser($admin);
        $this->client->request('GET', '/admin/de/customers/'.$foreign->getCustomer()->getId().'/api-credentials'); self::assertContains($this->client->getResponse()->getStatusCode(), [403, 404]);
        $url = '/admin/de/customers/'.$own->getCustomer()->getId().'/webhooks'; $crawler = $this->client->request('GET', $url); self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Erstellen')->form(); $this->client->submit($form, ['form[url]' => 'https://hooks.example.org/events', 'form[events]' => ['license.created']]); self::assertResponseIsSuccessful();
        $endpoint = $this->em->getRepository(WebhookEndpoint::class)->findOneBy([]); self::assertStringStartsWith('enc:v1:', $endpoint->getSecretCiphertext());
        $secret = $this->client->getCrawler()->filter('#integration-secret')->text(); self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $secret); self::assertStringNotContainsString($secret, $endpoint->getSecretCiphertext());
        $this->client->request('GET', $url); self::assertStringNotContainsString($secret, $this->client->getResponse()->getContent());
        $this->client->request('POST', '/admin/de/webhooks/'.$endpoint->getId().'/disable', ['_token' => 'forged']); self::assertResponseStatusCodeSame(403);
    }
}
