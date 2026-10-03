<?php

namespace App\Tests\Functional;

use App\Entity\{License, Customer, User};
use App\Tests\Support\IsolatedWebTestCase;

final class CustomerScopeTest extends IsolatedWebTestCase
{
    public function testScopedListsChoicesHistoryAndDirectIdsExcludeAnotherCustomer(): void
    {
        $first = $this->license();
        $first->getCustomer()->setCompany('Assigned Customer');
        $first->getProduct()->setSlug('first'); $this->em->flush(); $second = $this->license();
        $second->getCustomer()->setCompany('Foreign Customer');
        $user = $this->user('ROLE_SALES');
        $user->setGlobalAccess(false)->addCustomer($first->getCustomer());
        $this->em->flush();
        $this->client->loginUser($user);
        $router = static::getContainer()->get('router');
        $crawler = $this->client->request('GET', $router->generate('admin_license_index', ['_locale' => 'de']));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Assigned Customer', $crawler->text());
        self::assertStringNotContainsString('Foreign Customer', $crawler->text());
        $crawler = $this->client->request('GET', $router->generate('admin_license_new', ['_locale' => 'de']));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Assigned Customer', $crawler->filter('select[name="License[customer]"]')->text());
        self::assertStringNotContainsString('Foreign Customer', $crawler->filter('select[name="License[customer]"]')->text());
        $this->client->request('GET', '/admin/de/licenses/'.$second->getId().'/history');
        self::assertContains($this->client->getResponse()->getStatusCode(), [403, 404]);
        $this->client->request('GET', $router->generate('admin_customer_edit', ['_locale' => 'de', 'entityId' => (string) $second->getCustomer()->getId()]));
        self::assertContains($this->client->getResponse()->getStatusCode(), [403, 404]);
        $this->client->request('GET', $router->generate('admin_user_edit', ['_locale' => 'de', 'entityId' => (string) $user->getId()]));
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/admin/de/licenses/'.$first->getId().'/history');
        self::assertResponseIsSuccessful();
    }

    public function testForgedCustomerSelectionCannotMoveLicense(): void
    {
        $first = $this->license(); $first->getProduct()->setSlug('first'); $this->em->flush(); $second = $this->license();
        $user = $this->user('ROLE_SALES')->setGlobalAccess(false)->addCustomer($first->getCustomer());
        $this->em->flush(); $this->client->loginUser($user);
        $url = static::getContainer()->get('router')->generate('admin_license_edit', ['_locale' => 'de', 'entityId' => (string) $first->getId()]);
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="License"]')->form(); $values = $form->getPhpValues();
        $values['License']['customer'] = (string) $second->getCustomer()->getId();
        $this->client->request('POST', $url, $values);
        self::assertResponseStatusCodeSame(422);
        $this->em->clear();
        self::assertSame((string) $first->getCustomer()->getId(), (string) $this->em->find(License::class, $first->getId())->getCustomer()->getId());
    }

    public function testInstallationTenantDoesNotGrantForeignCustomerAccess(): void
    {
        $first = $this->license(); $first->getProduct()->setSlug('first'); $this->em->flush(); $second = $this->license();
        $user = $this->user('ROLE_SUPPORT')->setGlobalAccess(false)->addCustomer($first->getCustomer());
        $this->em->flush(); $this->client->loginUser($user);
        $this->client->jsonRequest('POST', '/api/v1/licenses/activate', ['licenseKey' => $second->getLicenseKey(), 'product' => 'test', 'tenant' => 'same-installation-tenant', 'domain' => 'foreign.example.org']);
        self::assertResponseStatusCodeSame(403);
        $this->em->getFilters()->disable('customer_scope');
        self::assertSame(0, $this->em->getRepository(\App\Entity\Activation::class)->count([]));
    }

    public function testGlobalAdministrationAndScopedAuditOwnership(): void
    {
        $first = $this->license(); $first->getProduct()->setSlug('first'); $this->em->flush(); $second = $this->license();
        $first->getCustomer()->setCompany('OwnCustomer'); $second->getCustomer()->setCompany('OtherCustomer');
        $this->em->flush();
        $global = $this->user(); $scoped = $this->user('ROLE_VIEWER')->setGlobalAccess(false)->addCustomer($first->getCustomer()); $this->em->flush();
        $this->client->loginUser($scoped); $this->client->request('GET', '/admin/de'); self::assertResponseIsSuccessful();
        $audits = $this->em->getRepository(\App\Entity\AuditLog::class)->findAll();
        self::assertNotEmpty($audits);
        foreach ($audits as $entry) { self::assertSame((string) $first->getCustomer()->getId(), $entry->getContext()['customerId']); }
        $this->client->loginUser($global); $this->client->request('GET', '/admin/de/licenses/'.$second->getId().'/history'); self::assertResponseIsSuccessful();
        self::assertCount(2, $this->em->getRepository(Customer::class)->findAll());
    }
}
