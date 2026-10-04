<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Repository\ActivationRepository;
use App\Repository\CustomerRepository;
use App\Repository\LicenseRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\User\UserProviderInterface;

class DashboardTest extends WebTestCase
{
    public function testDashboardRendersStatisticsAndLicenseLinks(): void
    {
        $client = static::createClient();
        $container = static::getContainer();

        $customers = $this->createStub(CustomerRepository::class);
        $customers->method('getDashboardStatistics')->willReturn(['total' => 5, 'active' => 3]);
        $container->set(CustomerRepository::class, $customers);

        $activations = $this->createStub(ActivationRepository::class);
        $activations->method('getDashboardStatistics')->willReturn(['total' => 7, 'active' => 4]);
        $container->set(ActivationRepository::class, $activations);

        $licenses = $this->createStub(LicenseRepository::class);
        $licenses->method('getDashboardStatistics')->willReturn([
            'total' => 10, 'active' => 6, 'expiring' => 2,
            'expired' => 1, 'suspended' => 2, 'revoked' => 1,
        ]);
        $licenses->method('getStatisticsByProduct')->willReturn([
            ['productId' => 'test-product', 'productName' => 'Testmodul', 'productSlug' => 'testmodul', 'productActive' => false,
                'total' => 10, 'active' => 6, 'suspended' => 2, 'revoked' => 1, 'expired' => 1, 'activationCount' => 4],
        ]);
        $container->set(LicenseRepository::class, $licenses);

        $user = (new User())->setEmail('dashboard@example.org')->setRoles(['ROLE_ADMIN'])->setPassword('test-password-hash');
        $provider = $this->createStub(UserProviderInterface::class);
        $provider->method('refreshUser')->willReturn($user);
        $provider->method('supportsClass')->willReturn(true);
        $container->set('security.user.provider.concrete.app_user_provider', $provider);
        $user->enableTwoFactor('JBSWY3DPEHPK3PXP', []);
        $client->loginUser($user, 'main', ['2fa_complete' => true]);

        $crawler = $client->request('GET', '/admin/de');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('body[data-ea-dark-scheme-is-enabled="true"]');
        self::assertSelectorExists('[data-ea-color-scheme="light"]');
        self::assertSelectorExists('[data-ea-color-scheme="dark"]');
        self::assertSelectorExists('link[href*="css/admin-theme.css?v=20261004-blue-theme"]');
        self::assertSelectorTextContains('.content-header', 'Lizenzübersicht');
        self::assertSelectorTextContains('table tbody', 'Testmodul');
        self::assertSelectorTextContains('table tbody', '10');
        self::assertSelectorTextContains('table tbody .badge', 'Inaktiv');
        self::assertSelectorTextContains('table tbody td:last-child', '4');

        $licensePath = $container->get('router')->generate('admin_license_index', ['_locale' => 'de']);
        $allLicensesUrl = $crawler->selectLink('Alle Lizenzen')->link()->getUri();
        self::assertSame($licensePath, parse_url($allLicensesUrl, PHP_URL_PATH));
        self::assertNull(parse_url($allLicensesUrl, PHP_URL_QUERY));

        foreach (['active', 'expiring', 'expired', 'suspended', 'revoked'] as $view) {
            $links = $crawler->filter(sprintf('a[href*="licenseView=%s"]', $view));
            self::assertGreaterThan(0, $links->count(), $view);
            $url = $links->first()->link()->getUri();
            self::assertSame($licensePath, parse_url($url, PHP_URL_PATH));
            parse_str(parse_url($url, PHP_URL_QUERY), $parameters);
            self::assertSame(['licenseView' => $view], $parameters);
        }
    }
}
