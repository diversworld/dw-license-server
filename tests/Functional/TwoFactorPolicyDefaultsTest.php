<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Security\TwoFactorPolicy;
use App\Tests\Support\IsolatedWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class TwoFactorPolicyDefaultsTest extends IsolatedWebTestCase
{
    private array $environment = [];

    protected function setUp(): void
    {
        foreach (['TWO_FACTOR_REQUIRED_ROLES', 'TWO_FACTOR_REQUIRED_ACTIONS'] as $name) {
            $this->environment[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);
        }
        parent::setUp();
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            foreach ($this->environment as $name => [$env, $server, $process]) {
                unset($_ENV[$name], $_SERVER[$name]);
                if ($env !== null) { $_ENV[$name] = $env; }
                if ($server !== null) { $_SERVER[$name] = $server; }
                putenv($process === false ? $name : $name.'='.$process);
            }
        }
    }

    public function testSuperAdminCanCreateCustomerAccountWithoutPolicyEnvironmentVariables(): void
    {
        $license = $this->license();
        $this->client->loginUser($this->user('ROLE_SUPER_ADMIN'));
        $url = static::getContainer()->get('router')->generate('admin_user_new');
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $values = $crawler->filter('form[name="User"]')->form()->getPhpValues();
        $values['User']['firstname'] = 'New';
        $values['User']['lastname'] = 'Customer';
        $values['User']['email'] = 'legacy-policy-customer@example.test';
        $values['User']['customers'] = [(string) $license->getCustomer()->getId()];
        $values['User']['plainPassword'] = ['first' => 'Strong-test-password-123', 'second' => 'Strong-test-password-123'];
        $this->client->request('POST', $url, $values);
        self::assertResponseRedirects();
        $created = $this->em->getRepository(User::class)->findOneBy(['email' => 'legacy-policy-customer@example.test']);
        self::assertNotNull($created);
        self::assertContains('ROLE_CUSTOMER', $created->getRoles());
        self::assertCount(1, $created->getCustomers());
        self::assertFalse($created->hasGlobalAccess());
        self::assertTrue(static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($created, 'Strong-test-password-123'));
    }

    public function testExplicitRequiredRoleStillEnforcesEnrollmentWhenActionsVariableIsMissing(): void
    {
        $_ENV['TWO_FACTOR_REQUIRED_ROLES'] = $_SERVER['TWO_FACTOR_REQUIRED_ROLES'] = '["ROLE_SUPER_ADMIN"]';
        $this->client->loginUser($this->user('ROLE_SUPER_ADMIN'));
        $this->client->request('GET', static::getContainer()->get('router')->generate('admin_user_new'));
        self::assertResponseRedirects('/security/2fa/setup');
    }

    public function testExplicitRequiredActionStillRejectsUnverifiedUserWhenRolesVariableIsMissing(): void
    {
        $_ENV['TWO_FACTOR_REQUIRED_ACTIONS'] = $_SERVER['TWO_FACTOR_REQUIRED_ACTIONS'] = '["USER_MANAGE"]';
        $this->client->loginUser($this->user('ROLE_SUPER_ADMIN'));
        $this->client->request('GET', static::getContainer()->get('router')->generate('admin_user_new'));
        self::assertResponseStatusCodeSame(403);
    }

    public function testExplicitInvalidPolicyDoesNotSilentlyFallBackToVoluntaryEnrollment(): void
    {
        $_ENV['TWO_FACTOR_REQUIRED_ROLES'] = $_SERVER['TWO_FACTOR_REQUIRED_ROLES'] = '["ROLE_DOES_NOT_EXIST"]';
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown assignable role');
        static::getContainer()->get(TwoFactorPolicy::class);
    }
}
