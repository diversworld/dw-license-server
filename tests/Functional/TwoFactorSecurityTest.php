<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Security\{TotpSecretCipher, TwoFactorPolicy};
use App\Tests\Support\IsolatedWebTestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class TwoFactorSecurityTest extends IsolatedWebTestCase
{
    private const string SECRET = 'JBSWY3DPEHPK3PXP';
    private const string PASSWORD = 'security-test-password';
    private const string BACKUP = 'unused-backup-code';

    protected function setUp(): void
    {
        parent::setUp();
        static::getContainer()->get('cache.rate_limiter')->clear();
    }

    public function testPasswordAndActualTotpCodeCompleteEncryptedAccountLogin(): void
    {
        $user = $this->account();
        $email = $user->getEmail();
        $this->em->clear();
        $this->client->request('GET', '/login');
        $this->client->submitForm('Anmelden', ['_username' => $email, '_password' => self::PASSWORD]);
        self::assertResponseRedirects();
        $this->client->request('GET', '/admin/de'); self::assertResponseRedirects('/2fa');
        $this->client->followRedirect(); self::assertResponseIsSuccessful();
        $this->client->submitForm('Bestätigen', ['_auth_code' => \OTPHP\TOTP::createFromSecret(self::SECRET)->now()]);
        self::assertResponseRedirects();
        $this->client->request('GET', '/admin/de'); self::assertResponseIsSuccessful();
    }

    public function testRecoveryCodeCompletesLoginAfterRejectedTotpAndCannotBeReused(): void
    {
        $user = $this->account();
        $email = $user->getEmail();
        $this->em->clear();
        foreach ([true, false] as $firstLogin) {
            $this->client->restart();
            $this->client->request('GET', '/login');
            $this->client->submitForm('Anmelden', ['_username' => $email, '_password' => self::PASSWORD]);
            $this->client->request('GET', '/2fa');
            $this->client->submitForm('Bestätigen', ['_auth_code' => 'invalid']);
            self::assertResponseRedirects('/2fa');
            $this->client->followRedirect();
            $this->client->submitForm('Bestätigen', ['_auth_code' => self::BACKUP]);
            if ($firstLogin) {
                self::assertResponseRedirects();
                $this->client->request('GET', '/admin/de');
                self::assertResponseIsSuccessful();
            } else {
                self::assertResponseRedirects('/2fa');
                $this->client->followRedirect();
                self::assertSelectorExists('.alert-danger');
                $this->client->request('GET', '/admin/de');
                self::assertResponseRedirects('/2fa');
            }
        }
    }

    public function testDiagnosticsReportStoredStateWithoutSecretsOrWrites(): void
    {
        $user = $this->account();
        $before = $this->em->getConnection()->fetchAssociative('SELECT * FROM user WHERE email = ?', [$user->getEmail()]);
        $command = new \Symfony\Component\Console\Tester\CommandTester(static::getContainer()->get(\App\Command\DiagnoseTwoFactorCommand::class));
        self::assertSame(0, $command->execute(['email' => $user->getEmail()]));
        $display = $command->getDisplay();
        self::assertStringContainsString('Recovery-code listener: enabled', $display);
        self::assertStringContainsString('Unused recovery codes: 1', $display);
        self::assertStringContainsString('Authenticator storage: encrypted', $display);
        self::assertStringContainsString('Authenticator readable: yes', $display);
        foreach ([self::SECRET, self::BACKUP, $before['totp_secret'], hash('sha256', self::BACKUP)] as $secret) {
            self::assertStringNotContainsString($secret, $display);
        }
        self::assertSame($before, $this->em->getConnection()->fetchAssociative('SELECT * FROM user WHERE email = ?', [$user->getEmail()]));
        self::assertSame(1, $command->execute(['email' => 'missing@example.test']));
        self::assertStringContainsString('Account not found', $command->getDisplay());
    }

    public function testDiagnosticsIdentifyMissingKeyWithoutCreatingAReplacement(): void
    {
        $user = $this->account();
        $keyFile = sys_get_temp_dir().'/missing-diagnostic-key-'.bin2hex(random_bytes(8));
        $cipher = new TotpSecretCipher(new \Symfony\Component\Filesystem\Filesystem(), new \Symfony\Component\Lock\LockFactory(new \Symfony\Component\Lock\Store\InMemoryStore()), $keyFile, false);
        $command = new \Symfony\Component\Console\Tester\CommandTester(new \App\Command\DiagnoseTwoFactorCommand(
            $this->em->getConnection(), $cipher,
            static::getContainer()->get(\Symfony\Component\EventDispatcher\EventDispatcherInterface::class), static::$kernel,
        ));
        self::assertSame(1, $command->execute(['email' => $user->getEmail()]));
        self::assertStringContainsString('Unused recovery codes: 1', $command->getDisplay());
        self::assertStringContainsString('Authenticator encryption key is missing.', $command->getDisplay());
        self::assertStringNotContainsString(self::SECRET, $command->getDisplay());
        self::assertFileDoesNotExist($keyFile);
        self::assertTrue($user->isBackupCode(self::BACKUP));
    }

    public function testDiagnosticsIdentifyAnUnregisteredRecoveryCodeListener(): void
    {
        $user = $this->account();
        $command = new \Symfony\Component\Console\Tester\CommandTester(new \App\Command\DiagnoseTwoFactorCommand(
            $this->em->getConnection(), static::getContainer()->get(TotpSecretCipher::class),
            new \Symfony\Component\EventDispatcher\EventDispatcher(), static::$kernel,
        ));
        self::assertSame(1, $command->execute(['email' => $user->getEmail()]));
        self::assertStringContainsString('Recovery-code listener: MISSING', $command->getDisplay());
        self::assertStringContainsString('Authenticator readable: yes', $command->getDisplay());
        self::assertTrue($user->isBackupCode(self::BACKUP));
    }

    public function testRequiredRoleCannotSkipOrDeclineEvenWithValidCsrf(): void
    {
        $this->policy(['ROLE_ADMIN']);
        $user = $this->account(false);
        $this->client->loginUser($user);
        $this->client->request('GET', '/admin/de');
        self::assertResponseRedirects('/security/2fa/setup');
        $this->client->followRedirect();
        self::assertSelectorNotExists('form[action$="/skip"]');
        self::assertSelectorNotExists('form[action$="/decline"]');
        $stack = static::getContainer()->get(RequestStack::class);
        $stack->push($this->client->getRequest());
        $token = static::getContainer()->get(CsrfTokenManagerInterface::class)->getToken('two_factor_decision')->getValue();
        $stack->getSession()->save(); $stack->pop();
        foreach (['skip', 'decline'] as $decision) {
            $this->client->request('POST', '/security/2fa/decision/'.$decision, ['_token' => $token]);
            self::assertResponseStatusCodeSame(403);
        }
        $this->client->request('GET', '/admin/de');
        self::assertResponseRedirects('/security/2fa/setup');
    }

    public function testSensitiveActionRequiresVerifiedTwoFactorWithoutChangingOptionalRolePolicy(): void
    {
        $this->policy([], ['LICENSE_REVOKE']);
        $user = $this->account(false);
        $license = $this->license();
        $url = '/admin/de/licenses/'.$license->getId().'/actions/revoke';
        $this->client->loginUser($user);
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', $url, ['form' => ['reason' => 'Forged request', '2fa_complete' => true]]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('active', $this->em->getConnection()->fetchOne('SELECT status FROM license'));
        $this->em->clear(); $user = $this->em->find(User::class, $user->getId());
        $user->enableTwoFactor(self::SECRET, [self::BACKUP]); $this->em->flush();
        $this->client->loginUser($user, 'main', ['2fa_complete' => true]);
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $this->client->submitForm('Aktion ausführen', ['form[reason]' => 'Verified administrative revocation']);
        self::assertResponseRedirects();
        self::assertSame('revoked', $this->em->getConnection()->fetchOne('SELECT status FROM license'));
    }

    public function testAuthenticatorStorageIsEncryptedAndSessionsContainNeitherSecretNorCiphertext(): void
    {
        $user = $this->account();
        $stored = $this->em->getConnection()->fetchOne('SELECT totp_secret FROM user');
        self::assertStringStartsWith(TotpSecretCipher::PREFIX, $stored);
        self::assertStringNotContainsString(self::SECRET, $stored);
        self::assertStringNotContainsString(self::SECRET, serialize($user));
        self::assertStringNotContainsString($stored, serialize($user));
        $this->em->clear();
        $fresh = $this->em->find(User::class, $user->getId());
        self::assertSame(self::SECRET, $fresh->getTotpAuthenticationConfiguration()->getSecret());
        self::assertTrue($fresh->isBackupCode(self::BACKUP));
    }

    public function testCodeRegenerationRequiresPasswordAndProofAndInvalidatesPreviousCodes(): void
    {
        $user = $this->account(); $this->client->loginUser($user, 'main', ['2fa_complete' => true]);
        $version = $user->getSecurityVersion();
        $this->client->request('GET', '/security/2fa/manage/codes');
        $this->client->submitForm('Änderung bestätigen', ['form[password]' => 'wrong-password', 'form[proof]' => self::BACKUP]);
        self::assertResponseIsSuccessful(); self::assertSelectorNotExists('li code');
        $this->em->clear();
        self::assertTrue($this->em->find(User::class, $user->getId())->isBackupCode(self::BACKUP));
        $this->client->request('GET', '/security/2fa/manage/codes');
        $this->client->submitForm('Änderung bestätigen', ['form[password]' => self::PASSWORD, 'form[proof]' => self::BACKUP]);
        self::assertResponseIsSuccessful(); self::assertSelectorCount(10, 'li code');
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control'));
        $newCode = $this->client->getCrawler()->filter('li code')->first()->text();
        $this->em->clear(); $fresh = $this->em->find(User::class, $user->getId());
        self::assertFalse($fresh->isBackupCode(self::BACKUP)); self::assertTrue($fresh->isBackupCode($newCode));
        self::assertGreaterThan($version, $fresh->getSecurityVersion());
        $this->client->request('GET', '/admin/de'); self::assertResponseRedirects('/login');
    }

    public function testAuthenticatorReplacementConfirmsBothOldAndNewFactors(): void
    {
        $user = $this->account(); $this->client->loginUser($user, 'main', ['2fa_complete' => true]);
        $this->client->request('GET', '/security/2fa/manage/rotate');
        $secret = $this->client->getCrawler()->filter('main p code')->text();
        self::assertNotSame(self::SECRET, $secret);
        $this->client->submitForm('Änderung bestätigen', ['form[password]' => self::PASSWORD, 'form[proof]' => self::BACKUP, 'form[newCode]' => 'invalid']);
        self::assertSelectorNotExists('li code');
        $this->em->clear(); self::assertTrue($this->em->find(User::class, $user->getId())->isBackupCode(self::BACKUP));
        $this->client->request('GET', '/security/2fa/manage/rotate');
        $this->client->submitForm('Änderung bestätigen', ['form[password]' => self::PASSWORD, 'form[proof]' => self::BACKUP, 'form[newCode]' => \OTPHP\TOTP::createFromSecret($secret)->now()]);
        self::assertSelectorCount(10, 'li code');
        $this->em->clear(); $fresh = $this->em->find(User::class, $user->getId());
        self::assertSame($secret, $fresh->getTotpAuthenticationConfiguration()->getSecret());
        self::assertFalse($fresh->isBackupCode(self::BACKUP));
    }

    public function testControlledRecoveryIsAvailableDuringChallengeAndCannotBypassRequiredEnrollment(): void
    {
        $this->policy(['ROLE_ADMIN']);
        $user = $this->account();
        $this->client->request('GET', '/login');
        $this->client->submitForm('Anmelden', ['_username' => $user->getEmail(), '_password' => self::PASSWORD]);
        $this->client->request('GET', '/security/2fa/recover');
        self::assertResponseIsSuccessful();
        $this->client->submitForm('Änderung bestätigen', ['form[password]' => self::PASSWORD, 'form[proof]' => self::BACKUP]);
        self::assertResponseRedirects('/login');
        $this->em->clear(); $fresh = $this->em->find(User::class, $user->getId());
        self::assertFalse($fresh->isTotpAuthenticationEnabled());
        self::assertFalse($fresh->isBackupCode(self::BACKUP));
        $this->client->request('GET', '/login');
        $this->client->submitForm('Anmelden', ['_username' => $fresh->getEmail(), '_password' => self::PASSWORD]);
        $this->client->request('GET', '/admin/de');
        self::assertResponseRedirects('/security/2fa/setup');
    }

    public function testSecurityVersionInvalidatesAnAlreadyAuthenticatedSession(): void
    {
        $user = $this->account(); $this->client->loginUser($user, 'main', ['2fa_complete' => true]);
        $this->client->request('GET', '/admin/de'); self::assertResponseIsSuccessful();
        $this->em->clear(); $fresh = $this->em->find(User::class, $user->getId());
        $fresh->revokeSessions(); $this->em->flush(); $this->em->clear();
        $this->client->request('GET', '/admin/de'); self::assertResponseRedirects('/login');
    }

    public function testMissingCsrfCannotRegenerateCodes(): void
    {
        $user = $this->account(); $this->client->loginUser($user, 'main', ['2fa_complete' => true]);
        $version = $user->getSecurityVersion();
        $this->client->request('POST', '/security/2fa/manage/codes', ['form' => ['password' => self::PASSWORD, 'proof' => self::BACKUP]]);
        self::assertSelectorNotExists('li code');
        $this->em->clear(); $fresh = $this->em->find(User::class, $user->getId());
        self::assertSame($version, $fresh->getSecurityVersion()); self::assertTrue($fresh->isBackupCode(self::BACKUP));
    }

    public function testPendingSetupSecretIsEncryptedExpiresAndIsBoundToItsOwner(): void
    {
        $user = $this->account(false); $this->client->loginUser($user);
        $this->client->request('GET', '/security/2fa/setup');
        $plain = $this->client->getCrawler()->filter('#two-factor-secret')->text();
        $session = $this->client->getRequest()->getSession();
        $pending = $session->get('two_factor_setup_secret');
        self::assertSame((string) $user->getId(), $pending['owner']);
        self::assertGreaterThan(time(), $pending['expiresAt']);
        self::assertStringStartsWith(TotpSecretCipher::PREFIX, $pending['secret']);
        self::assertStringNotContainsString($plain, serialize($pending));
        $pending['expiresAt'] = 0; $session->set('two_factor_setup_secret', $pending); $session->save();
        $this->client->request('GET', '/security/2fa/setup');
        self::assertNotSame($plain, $this->client->getCrawler()->filter('#two-factor-secret')->text());
    }

    public function testExplicitLegacyConversionPreservesAuthenticatorAndSessionVersion(): void
    {
        $user = $this->account();
        $version = $user->getSecurityVersion();
        $this->em->getConnection()->executeStatement('UPDATE user SET totp_secret = ?', [self::SECRET]);
        $this->em->clear();
        $command = new \Symfony\Component\Console\Tester\CommandTester(new \App\Command\EncryptLegacyTotpCommand($this->em, static::getContainer()->get(TotpSecretCipher::class)));
        self::assertSame(0, $command->execute([]));
        self::assertStringContainsString('Encrypted 1', $command->getDisplay());
        self::assertStringNotContainsString(self::SECRET, $command->getDisplay());
        self::assertStringStartsWith(TotpSecretCipher::PREFIX, $this->em->getConnection()->fetchOne('SELECT totp_secret FROM user'));
        $this->em->clear(); $fresh = $this->em->find(User::class, $user->getId());
        self::assertSame(self::SECRET, $fresh->getTotpAuthenticationConfiguration()->getSecret());
        self::assertSame($version, $fresh->getSecurityVersion());
        self::assertSame(0, $command->execute([]));
        self::assertStringContainsString('Encrypted 0', $command->getDisplay());
    }

    public function testManagementRequestsAreRateLimited(): void
    {
        $user = $this->account(); $this->client->loginUser($user, 'main', ['2fa_complete' => true]);
        for ($i = 0; $i < 11; ++$i) { $this->client->request('GET', '/security/2fa/manage/codes'); }
        self::assertResponseStatusCodeSame(429);
        $this->em->clear(); self::assertTrue($this->em->find(User::class, $user->getId())->isBackupCode(self::BACKUP));
    }

    public function testKeyInitializerRefusesToReplaceAMissingKeyForEncryptedAccounts(): void
    {
        $this->account();
        $keyFile = sys_get_temp_dir().'/missing-totp-key-'.bin2hex(random_bytes(8));
        $cipher = new TotpSecretCipher(new \Symfony\Component\Filesystem\Filesystem(), new \Symfony\Component\Lock\LockFactory(new \Symfony\Component\Lock\Store\InMemoryStore()), $keyFile, false);
        $command = new \Symfony\Component\Console\Tester\CommandTester(new \App\Command\InitializeTotpKeyCommand($cipher, $this->em));
        self::assertSame(1, $command->execute([]));
        self::assertFileDoesNotExist($keyFile);
        self::assertStringContainsString('Restore', $command->getDisplay());
        self::assertStringContainsString('Authenticator encryption key is missing.', $command->getDisplay());
    }

    public function testConfigurationRejectsUnknownRolesAndActions(): void
    {
        $hierarchy = static::getContainer()->get(RoleHierarchyInterface::class);
        foreach ([[['ROLE_UNKNOWN'], []], [[], ['UNKNOWN_ACTION']]] as [$roles, $actions]) {
            try { new TwoFactorPolicy($hierarchy, $roles, $actions); self::fail('Invalid security configuration must fail.'); }
            catch (\InvalidArgumentException $error) { self::assertStringContainsString('unknown', $error->getMessage()); }
        }
    }

    private function account(bool $enabled = true): User
    {
        $user = $this->user();
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        if ($enabled) { $user->enableTwoFactor(self::SECRET, [self::BACKUP]); }
        $this->em->flush();

        return $user;
    }

    private function policy(array $roles = [], array $actions = []): void
    {
        static::getContainer()->set(TwoFactorPolicy::class, new TwoFactorPolicy(static::getContainer()->get(RoleHierarchyInterface::class), $roles, $actions));
    }
}
