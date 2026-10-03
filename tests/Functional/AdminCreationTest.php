<?php

namespace App\Tests\Functional;

use App\Entity\{Product, User};
use App\Tests\Support\IsolatedWebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdminCreationTest extends IsolatedWebTestCase
{
    public static function invalidNumbers(): iterable
    {
        yield 'empty installation cap' => ['maxInstallations', ''];
        yield 'zero installation cap' => ['maxInstallations', '0'];
        yield 'empty token lifetime' => ['tokenLifetimeSeconds', ''];
        yield 'too short lifetime' => ['tokenLifetimeSeconds', '10'];
        yield 'empty grace' => ['gracePeriodSeconds', ''];
        yield 'negative grace' => ['gracePeriodSeconds', '-1'];
    }

    #[DataProvider('invalidNumbers')]
    public function testInvalidNumericProductInputsShowFormErrors(string $field, string $value): void
    {
        $this->client->loginUser($this->user());
        $form = $this->client->request('GET', static::getContainer()->get('router')->generate('admin_product_new'))->filter('form[name="Product"]')->form();
        $values = $form->getPhpValues(); $values['Product']['slug'] = 'new-product'; $values['Product']['name'] = 'New product'; $values['Product'][$field] = $value;
        $this->client->request('POST', $form->getUri(), $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->em->getRepository(Product::class)->count([]));
    }

    public function testValidProductWithZeroGraceCanBeCreated(): void
    {
        $this->client->loginUser($this->user());
        $url = static::getContainer()->get('router')->generate('admin_product_new');
        $form = $this->client->request('GET', $url)->filter('form[name="Product"]')->form();
        $values = $form->getPhpValues(); $values['Product']['slug'] = 'valid-product'; $values['Product']['name'] = 'Valid'; $values['Product']['maxInstallations'] = '3'; $values['Product']['gracePeriodSeconds'] = '0';
        $this->client->request('POST', $form->getUri(), $values); self::assertResponseRedirects();
        $product = $this->em->getRepository(Product::class)->findOneBy(['slug' => 'valid-product']); self::assertNotNull($product); self::assertSame(3, $product->getMaxInstallations()); self::assertSame(0, $product->getGracePeriodSeconds());
    }

    public function testAdminCanCreateCustomerAccountWithHashedPassword(): void
    {
        $license = $this->license(); $this->client->loginUser($this->user());
        $url = static::getContainer()->get('router')->generate('admin_user_new');
        $crawler = $this->client->request('GET', $url); self::assertResponseIsSuccessful();
        $values = $crawler->filter('form[name="User"]')->form()->getPhpValues();
        $values['User']['firstname'] = 'Portal'; $values['User']['lastname'] = 'Customer'; $values['User']['email'] = 'portal-new@example.test';
        $values['User']['customers'] = [(string) $license->getCustomer()->getId()];
        $values['User']['plainPassword'] = ['first' => 'Strong-test-password-123', 'second' => 'Strong-test-password-123'];
        $this->client->request('POST', $url, $values); self::assertResponseRedirects();
        $created = $this->em->getRepository(User::class)->findOneBy(['email' => 'portal-new@example.test']); self::assertNotNull($created); self::assertFalse($created->hasGlobalAccess()); self::assertContains('ROLE_CUSTOMER', $created->getRoles()); self::assertCount(1, $created->getCustomers());
        self::assertNotSame('Strong-test-password-123', $created->getPassword()); self::assertTrue(static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($created, 'Strong-test-password-123'));
        $this->client->loginUser($created); $this->client->request('GET', '/portal'); self::assertResponseRedirects('/security/2fa/setup');
        $crawler = $this->client->followRedirect(); self::assertResponseIsSuccessful(); $this->client->submit($crawler->filter('form[action$="/skip"]')->form()); self::assertResponseRedirects('/portal');
        $this->client->followRedirect(); self::assertResponseIsSuccessful(); $this->client->request('GET', '/admin/de'); self::assertResponseStatusCodeSame(403);
    }

    public static function invalidAccounts(): iterable
    {
        yield 'missing assignment' => ['customers', []];
        yield 'weak password' => ['plainPassword', ['first' => 'short', 'second' => 'short']];
        yield 'mismatched password' => ['plainPassword', ['first' => 'Strong-test-password-123', 'second' => 'Another-password-123']];
        yield 'forged Super Admin role' => ['roles', ['ROLE_SUPER_ADMIN']];
    }

    #[DataProvider('invalidAccounts')]
    public function testInvalidOrEscalatedNewAccountIsNotStored(string $field, array $value): void
    {
        $license = $this->license(); $this->client->loginUser($this->user());
        $url = static::getContainer()->get('router')->generate('admin_user_new');
        $values = $this->client->request('GET', $url)->filter('form[name="User"]')->form()->getPhpValues();
        $values['User']['firstname'] = 'Test'; $values['User']['lastname'] = 'Customer'; $values['User']['email'] = 'invalid-new@example.test'; $values['User']['customers'] = [(string) $license->getCustomer()->getId()]; $values['User']['plainPassword'] = ['first' => 'Strong-test-password-123', 'second' => 'Strong-test-password-123']; $values['User'][$field] = $value;
        $this->client->request('POST', $url, $values); self::assertResponseStatusCodeSame(422); self::assertNull($this->em->getRepository(User::class)->findOneBy(['email' => 'invalid-new@example.test']));
    }
}
