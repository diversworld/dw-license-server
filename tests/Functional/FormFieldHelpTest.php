<?php

namespace App\Tests\Functional;

use App\Entity\Activation;
use App\Entity\Customer;
use App\Entity\LicensePlan;
use App\Entity\User;
use App\Tests\Support\IsolatedWebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

final class FormFieldHelpTest extends IsolatedWebTestCase
{
    public static function languages(): iterable
    {
        foreach (['de', 'en', 'fr', 'es'] as $locale) {
            yield $locale => [$locale];
        }
    }

    #[DataProvider('languages')]
    public function testVisibleFieldsHaveTranslatedHelp(string $locale): void
    {
        $license = $this->license();
        $license->getProduct()->setAllowedFeatures(['sla'])->setFeatureQuotas(['sla' => 10]);
        $license->setFeatures(['sla'])->setQuotas(['sla' => 2]);
        $license->getCustomer()->setReminderRecipients(['extra@example.test']);
        $plan = (new LicensePlan())->setProduct($license->getProduct())->setName('Help test')->setFeatures(['sla'])->setQuotas(['sla' => 5]);
        $activation = (new Activation())->setLicense($license)->setTenant('help-test')->setDomain('example.org');
        $this->em->persist($plan);
        $this->em->persist($activation);
        $this->em->flush();
        $user = $this->user();
        $this->client->loginUser($user);
        $router = static::getContainer()->get('router');
        $routes = [
            'admin_customer_new' => [], 'admin_user_new' => [], 'admin_product_new' => [],
            'admin_license_plan_new' => [], 'admin_license_new' => [], 'admin_license_from_plan' => [],
            'admin_license_plan_edit' => ['entityId' => (string) $plan->getId()],
            'admin_customer_edit' => ['entityId' => (string) $license->getCustomer()->getId()],
            'admin_user_edit' => ['entityId' => (string) $user->getId()],
            'admin_profile_edit' => ['entityId' => (string) $user->getId()],
            'admin_product_edit' => ['entityId' => (string) $license->getProduct()->getId()],
            'admin_license_edit' => ['entityId' => (string) $license->getId()],
            'admin_activation_edit' => ['entityId' => (string) $activation->getId()],
            'admin_license_action' => ['id' => (string) $license->getId(), 'action' => 'renew'],
            'admin_record_archive' => ['id' => (string) $license->getId(), 'type' => 'license', 'action' => 'archive'],
            'admin_api_credentials' => ['id' => (string) $license->getCustomer()->getId()],
            'admin_webhooks' => ['id' => (string) $license->getCustomer()->getId()],
            'admin_license_issue' => ['id' => (string) $license->getId()],
            'dashboard_password' => [],
            'app_forgot_password_request' => [],
            'two_factor_setup' => [],
        ];

        foreach ($routes as $route => $parameters) {
            $crawler = $this->client->request('GET', $router->generate($route, $parameters + ['_locale' => $locale]));
            self::assertResponseIsSuccessful();
            $this->assertFieldsHaveHelp($crawler, $route);
            foreach ($crawler->filter('[data-prototype]') as $collection) {
                $this->assertFieldsHaveHelp(new Crawler('<form>'.$collection->getAttribute('data-prototype').'</form>'), $route.' added row');
            }
        }

        $twoFactorUser = $this->user();
        $twoFactorUser->enableTwoFactor('JBSWY3DPEHPK3PXP', []);
        $this->em->flush();
        $this->client->loginUser($twoFactorUser, 'main', ['2fa_complete' => true]);
        foreach (['/security/2fa/manage/rotate', '/security/2fa/manage/codes', '/security/2fa/recover'] as $url) {
            $crawler = $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            $this->assertFieldsHaveHelp($crawler, $url);
        }

        $customer = $this->em->find(Customer::class, $license->getCustomer()->getId());
        $customerUser = $this->user('ROLE_CUSTOMER')->setGlobalAccess(false)->addCustomer($customer);
        $this->em->flush();
        $this->client->loginUser($customerUser);
        foreach (['portal_download' => $license->getId(), 'portal_domain' => $activation->getId()] as $route => $id) {
            $crawler = $this->client->request('GET', $router->generate($route, ['id' => (string) $id, '_locale' => $locale]));
            self::assertResponseIsSuccessful();
            $this->assertFieldsHaveHelp($crawler, $route);
        }

        $customerUser = $this->em->find(User::class, $customerUser->getId());
        $resetToken = static::getContainer()->get(ResetPasswordHelperInterface::class)->generateResetToken($customerUser);
        $this->client->request('GET', $router->generate('app_reset_password', ['token' => $resetToken->getToken()]));
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        $this->assertFieldsHaveHelp($crawler, 'password reset');
    }

    private function assertFieldsHaveHelp(Crawler $crawler, string $route): void
    {
        $fields = $crawler->filter('form input:not([type="hidden"]):not([type="submit"]):not([type="button"]):not([name="query"]), form select, form textarea');
        self::assertGreaterThan(0, $fields->count(), $route);
        foreach ($fields as $field) {
            $xpath = new \DOMXPath($field->ownerDocument);
            $row = $xpath->query('ancestor::*[contains(concat(" ", normalize-space(@class), " "), " form-group ") or contains(concat(" ", normalize-space(@class), " "), " mb-3 ")][1]', $field)->item(0);
            $help = (new \Symfony\Component\DomCrawler\Crawler($row))->filter('.form-help, .form-text');
            self::assertGreaterThan(0, $help->count(), $route.' '.$field->getAttribute('name'));
            self::assertNotSame('', trim($help->text()), $route.' '.$field->getAttribute('name'));
            self::assertStringNotContainsString('form_help.', $help->text());
        }
    }

    public function testHandwrittenLoginFieldsHaveAccessibleHelp(): void
    {
        $this->client->request('GET', '/login');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#username[aria-describedby="username-help"]');
        self::assertSelectorExists('#password[aria-describedby="password-help"]');
        self::assertSelectorTextContains('#username-help', 'E-Mail-Adresse');
        self::assertSelectorTextContains('#password-help', 'Kennwort');
    }
}
