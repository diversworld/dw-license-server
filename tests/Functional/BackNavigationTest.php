<?php

namespace App\Tests\Functional;

use App\Tests\Support\IsolatedWebTestCase;

final class BackNavigationTest extends IsolatedWebTestCase
{
    public function testCustomerPagesLinkBackToThePortal(): void
    {
        $license = $this->license();
        $user = $this->user('ROLE_CUSTOMER')->setGlobalAccess(false)->addCustomer($license->getCustomer());
        $this->em->flush();
        $this->client->loginUser($user);
        $this->client->request('GET', '/portal/de');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-navigation-back]');

        $crawler = $this->client->request('GET', '/portal/de/licenses/'.$license->getId());
        self::assertResponseIsSuccessful();
        self::assertSame(static::getContainer()->get('router')->generate('portal_index', ['_locale' => 'de']), $crawler->filter('[data-navigation-back]')->attr('href'));
    }

    public function testAdminPagesLinkBackToTheDashboard(): void
    {
        $this->license();
        $this->client->loginUser($this->user());
        $router = static::getContainer()->get('router');
        $dashboard = $router->generate('admin', ['_locale' => 'de']);

        foreach (['admin_customer_index', 'admin_customer_new', 'dashboard_password', 'admin_license_from_plan'] as $route) {
            $crawler = $this->client->request('GET', $router->generate($route, ['_locale' => 'de']));
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('[data-navigation-back]', 'Zurück zur Übersicht');
            self::assertSame($dashboard, $crawler->filter('[data-navigation-back]')->attr('href'));
        }
    }
}
