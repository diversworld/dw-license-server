<?php

namespace App\Tests\Functional;

use App\Entity\{Customer, License, LicenseAction, Product, User};
use App\Security\BackupCodeManager;
use App\Service\{LicenseLifecycle, LicenseSigner, SigningKeyRotation};
use App\Tests\Fixtures\ContaoLicenseValidator;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\{LockFactory, Store\InMemoryStore};
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class PriorityFeaturesTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private License $license;
    private string $directory;
    private LicenseSigner $signer;
    private const string SECRET = 'JBSWY3DPEHPK3PXP';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        if (!\Doctrine\DBAL\Types\Type::hasType('uuid')) {
            \Doctrine\DBAL\Types\Type::addType('uuid', \Symfony\Bridge\Doctrine\Types\UuidType::class);
        }
        static::getContainer()->set('doctrine.dbal.default_connection', DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->directory = sys_get_temp_dir().'/license-policy-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        file_put_contents($this->directory.'/private.key', base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())));
        $this->signer = new LicenseSigner($this->directory.'/private.key');
        static::getContainer()->set(LicenseSigner::class, $this->signer);
        $customer = (new Customer())->setCompany('Test')->setFirstname('Test')->setLastname('Customer')->setEmail('customer@example.org')->setStreet('Test 1')->setZip('12345')->setCity('Berlin')->setActive(true);
        $product = (new Product())->setAllowedFeatures(['sla'])->setName('Test')->setSlug('test-policy')->setActive(true)->setTokenLifetimeSeconds(300)->setGracePeriodSeconds(600);
        $this->license = (new License())->setCustomer($customer)->setProduct($product)->setFeatures(['sla']);
        foreach ([$customer, $product, $this->license] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        if (isset($this->directory)) {
            (new Filesystem())->remove($this->directory);
        }
        parent::tearDown();
    }

    public function testPolicyBoundariesAndExpiryCapThroughApi(): void
    {
        $claims = $this->activate();
        self::assertSame(300, $claims['refresh_after'] - $claims['issued_at']);
        self::assertSame(900, $claims['grace_until'] - $claims['issued_at']);
        self::assertSame($claims['grace_until'], $claims['expires_at']);
        $token = $this->signer->sign($claims);
        $validator = new ContaoLicenseValidator(json_encode($this->signer->publicKeys()), 'tenant', 'example.org');
        self::assertSame('valid', $validator->validate($token, $claims['refresh_after'] - 1)['state']);
        self::assertSame('grace', $validator->validate($token, $claims['refresh_after'])['state']);
        self::assertTrue($validator->validate($token, $claims['grace_until'] - 1)['enabled']);
        self::assertFalse($validator->validate($token, $claims['grace_until'])['enabled']);
        $this->license->setExpiresAt(new \DateTimeImmutable('+100 seconds'));
        $this->em->flush();
        $claims = $this->activate();
        self::assertSame($this->license->getExpiresAt()->getTimestamp(), $claims['expires_at']);
        self::assertSame($claims['expires_at'], $claims['refresh_after']);
        $this->license->setExpiresAt(null)->getProduct()->setGracePeriodSeconds(0);
        $this->em->flush();
        $claims = $this->activate();
        self::assertSame($claims['refresh_after'], $claims['expires_at']);
    }

    public function testPreparedRotationPreservesLegacyAndNewTokens(): void
    {
        $claims = $this->activate();
        unset($claims['kid']);
        $encode = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $payload = $encode(json_encode($claims));
        $secret = base64_decode(file_get_contents($this->directory.'/private.key'));
        $legacy = $payload.'.'.$encode(sodium_crypto_sign_detached($payload, $secret));
        $old = $this->signer->sign($claims);
        $oldId = $this->signer->keyId();
        $rotation = new SigningKeyRotation($this->signer, new Filesystem(), new LockFactory(new InMemoryStore()), $this->directory);
        $newId = $rotation->prepare('test-operator', 'Scheduled rotation');
        self::assertSame($oldId, $this->signer->keyId(), 'Preparing a key must not activate it.');
        self::assertCount(1, $this->signer->publicKeys());
        $rotation->transition('publish', $newId, 'test-operator', 'Distribute new verification key', $rotation->fingerprint());
        self::assertCount(2, $this->signer->publicKeys());
        self::assertSame(0600, fileperms($this->directory.'/keys/'.$newId.'.key') & 0777);
        $rotation->activate($newId, 'test-operator', 'Distribution verified', $rotation->fingerprint(), true);
        $new = $this->signer->sign($claims);
        self::assertSame($newId, $this->signer->verify($new)['kid']);
        self::assertSame($oldId, $this->signer->verify($old)['kid']);
        self::assertSame($claims, $this->signer->verify($legacy));
        $validator = new ContaoLicenseValidator(json_encode($this->signer->publicKeys()), 'tenant', 'example.org');
        foreach ([$new, $old, $legacy] as $token) {
            self::assertTrue($validator->validate($token)['enabled']);
        }
        $parts = explode('.', $new);
        $tampered = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);
        $tampered['kid'] = '0000000000000000';
        $tamperedToken = $encode(json_encode($tampered)).'.'.$parts[1];
        self::assertFalse($validator->validate($tamperedToken)['enabled']);
        $this->expectException(\DomainException::class);
        $this->signer->verify($tamperedToken);
    }

    public function testEnrollmentRequiresPasswordAndValidAppCodeAndHidesSecretsFromAudit(): void
    {
        $user = $this->user('ROLE_ADMIN', false);
        $this->client->loginUser($user);
        $this->client->request('GET', '/admin/de');
        self::assertResponseRedirects('/security/2fa/setup');
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        $secret = $crawler->filter('#two-factor-secret')->text();
        $qrData = $crawler->filter('img.two-factor-qr')->attr('src');
        self::assertStringStartsWith('data:image/svg+xml;base64,', $qrData);
        $svg = base64_decode(substr($qrData, strlen('data:image/svg+xml;base64,')), true);
        self::assertStringContainsString('<svg', $svg);
        self::assertStringContainsString('<path', $svg);
        $this->client->submitForm('Zwei-Faktor-Anmeldung aktivieren', ['form[password]' => 'test-password-1234', 'form[code]' => 'invalid']);
        self::assertFalse($user->isTotpAuthenticationEnabled());
        self::assertStringContainsString('Der Code ist ungültig.', $this->client->getResponse()->getContent());
        $this->client->submitForm('Zwei-Faktor-Anmeldung aktivieren', ['form[password]' => 'test-password-1234', 'form[code]' => \OTPHP\TOTP::createFromSecret($secret)->now()]);
        self::assertResponseIsSuccessful();
        $user = $this->em->find(User::class, $user->getId());
        self::assertTrue($user->isTotpAuthenticationEnabled());
        self::assertSelectorCount(10, 'li code');
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control'));
        $logs = $this->em->getConnection()->fetchOne('SELECT context FROM audit_log WHERE event_type = ? ORDER BY id DESC LIMIT 1', ['user.updated']);
        self::assertStringNotContainsString($secret, $logs);
        self::assertStringContainsString('[redacted]', $logs);
        $this->client->request('GET', '/admin/de');
        self::assertResponseIsSuccessful();
    }

    public function testPasswordLoginRequiresTotpAndRecoveryCodeIsSingleUse(): void
    {
        $user = $this->user('ROLE_ADMIN');
        $this->passwordLogin($user);
        $this->client->request('GET', '/admin/de');
        self::assertResponseRedirects('/2fa');
        $this->client->followRedirect();
        $this->client->submitForm('Bestätigen', ['_auth_code' => 'invalid']);
        self::assertResponseRedirects('/2fa');
        $this->client->followRedirect();
        $this->client->submitForm('Bestätigen', ['_auth_code' => 'recovery-code']);
        self::assertResponseRedirects();
        $user = $this->em->find(User::class, $user->getId());
        self::assertFalse($user->isBackupCode('recovery-code'));
        $this->client->request('GET', '/admin/de');
        self::assertResponseIsSuccessful();
        self::assertFalse(static::getContainer()->get(BackupCodeManager::class)->isBackupCode($user, 'recovery-code'));
    }

    public function testSkippingAllowsRoleActionsForThisSessionAndPromptsAgainNextSession(): void
    {
        $user = $this->user('ROLE_ADMIN', false);
        $this->client->loginUser($user);
        $this->client->request('GET', '/security/2fa/setup');
        $this->client->submitForm('Für jetzt überspringen');
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        $this->client->request('GET', static::getContainer()->get('router')->generate('admin_product_new'));
        self::assertResponseIsSuccessful();
        $user = $this->em->find(User::class, $user->getId());
        self::assertFalse($user->isTotpAuthenticationEnabled());
        self::assertFalse($user->hasDeclinedTwoFactor());
        $this->client->restart();
        $this->client->loginUser($user);
        $this->client->request('GET', '/admin/de');
        self::assertResponseRedirects('/security/2fa/setup');
    }

    public function testDecliningPersistsAndEnrollmentRemainsAvailable(): void
    {
        $user = $this->user('ROLE_SALES', false);
        $this->client->loginUser($user);
        $this->client->request('GET', '/security/2fa/setup');
        $this->client->submitForm('2FA ablehnen');
        self::assertResponseRedirects();
        $user = $this->em->find(User::class, $user->getId());
        self::assertTrue($user->hasDeclinedTwoFactor());
        $this->client->restart();
        $this->client->loginUser($user);
        $this->client->request('GET', '/admin/de');
        self::assertResponseIsSuccessful();
        $setupLink = $this->client->getCrawler()->selectLink('Authenticator einrichten')->first()->link();
        $this->client->request('GET', static::getContainer()->get('router')->generate('admin_license_new'));
        self::assertResponseIsSuccessful();
        $this->client->request('GET', static::getContainer()->get('router')->generate('admin_product_new'));
        self::assertResponseStatusCodeSame(403);
        $crawler = $this->client->click($setupLink);
        self::assertResponseIsSuccessful();
        $secret = $crawler->filter('#two-factor-secret')->text();
        $this->client->submitForm('Zwei-Faktor-Anmeldung aktivieren', ['form[password]' => 'test-password-1234', 'form[code]' => \OTPHP\TOTP::createFromSecret($secret)->now()]);
        self::assertResponseIsSuccessful();
        $user = $this->em->find(User::class, $user->getId());
        self::assertTrue($user->isTotpAuthenticationEnabled());
        self::assertFalse($user->hasDeclinedTwoFactor());
    }

    public function testEnrollmentDecisionsRejectInvalidCsrfAndCannotDisableActiveTotp(): void
    {
        $user = $this->user('ROLE_ADMIN', false);
        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/security/2fa/setup');
        $form = $crawler->selectButton('2FA ablehnen')->form();
        $token = $form->getPhpValues()['_token'];
        $this->client->request('POST', '/security/2fa/decision/decline', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        $user = $this->em->find(User::class, $user->getId());
        self::assertFalse($user->hasDeclinedTwoFactor());
        $user->enableTwoFactor(self::SECRET, []);
        $this->em->flush();
        $this->client->loginUser($user, 'main', ['2fa_complete' => true]);
        foreach (['skip', 'decline'] as $decision) {
            $this->client->request('POST', '/security/2fa/decision/'.$decision, ['_token' => $token]);
            self::assertResponseStatusCodeSame(403);
        }
        $user = $this->em->find(User::class, $user->getId());
        self::assertTrue($user->isTotpAuthenticationEnabled());
    }

    public function testRolesAndLicenceActionsRecordActorReasonAndState(): void
    {
        $user = $this->user('ROLE_ADMIN');
        $this->client->loginUser($user, 'main', ['2fa_complete' => true]);
        $url = '/admin/licenses/'.$this->license->getId().'/actions/';
        $this->client->request('GET', $url.'pause');
        $this->client->submitForm('Aktion ausführen', ['form[reason]' => 'Customer request']);
        self::assertResponseRedirects();
        $this->license = $this->em->find(License::class, $this->license->getId());
        self::assertSame('suspended', $this->license->getStatus());
        $this->client->followRedirect();
        self::assertSelectorTextContains('tbody', 'Customer request');
        self::assertSelectorTextContains('tbody', $user->getFullname());
        foreach (['revoke', 'renew', 'withdraw_revocation'] as $action) {
            $this->client->request('GET', $url.$action);
            $values = ['form[reason]' => 'Approved '.$action];
            if ($action === 'renew') {
                $values['form[expiresAt]'] = (new \DateTimeImmutable('+30 days'))->format('Y-m-d\TH:i');
            }
            $this->client->submitForm('Aktion ausführen', $values);
            self::assertResponseRedirects();
        }
        $this->license = $this->em->find(License::class, $this->license->getId());
        self::assertSame('active', $this->license->getStatus());
        $entries = $this->em->getRepository(LicenseAction::class)->findBy(['license' => $this->license]);
        self::assertCount(4, $entries);
        self::assertEquals($user->getId(), $entries[0]->getPerformedBy()->getId());
        $this->client->request('GET', $url.'pause');
        $this->client->submitForm('Aktion ausführen', ['form[reason]' => '']);
        self::assertCount(4, $this->em->getRepository(LicenseAction::class)->findAll());
        foreach ([['ROLE_VIEWER', 'pause'], ['ROLE_SUPPORT', 'revoke'], ['ROLE_SALES', 'pause']] as [$role, $action]) {
            $actor = $this->user($role);
            $this->client->loginUser($actor, 'main', ['2fa_complete' => true]);
            $this->client->request('GET', $url.$action);
            self::assertResponseStatusCodeSame(403);
        }
    }

    public function testInvalidTransitionKeepsEntityManagerUsableAndOldSessionCannotWrite(): void
    {
        $user = $this->user('ROLE_ADMIN');
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
        $token->setAttribute('2fa_complete', true);
        static::getContainer()->get(TokenStorageInterface::class)->setToken($token);
        $service = static::getContainer()->get(LicenseLifecycle::class);
        try {
            $service->perform($this->license, 'reactivate', 'Invalid state');
            self::fail('Active licenses cannot be reactivated.');
        } catch (\DomainException $e) {
            self::assertSame('license_action.invalid_transition', $e->getMessage());
        }
        self::assertTrue($this->em->isOpen());
        self::assertCount(0, $this->em->getRepository(LicenseAction::class)->findAll());
        $this->client->loginUser($user);
        $this->client->request('POST', '/admin/licenses/'.$this->license->getId().'/actions/revoke');
        self::assertResponseRedirects('/login');
        $this->license = $this->em->find(License::class, $this->license->getId());
        self::assertSame('active', $this->license->getStatus());
    }

    public function testRolePermissionsApplyToCrudRoutesAndActionForms(): void
    {
        $router = static::getContainer()->get('router');
        foreach ([
            'ROLE_VIEWER' => ['admin_license_new' => 403, 'admin_customer_new' => 403, 'admin_product_new' => 403, 'admin_user_index' => 403, 'admin_license_index' => 200],
            'ROLE_SUPPORT' => ['admin_license_new' => 403, 'admin_customer_new' => 403, 'admin_product_new' => 403, 'admin_license_index' => 200],
            'ROLE_SALES' => ['admin_license_new' => 200, 'admin_customer_new' => 200, 'admin_product_new' => 403, 'admin_user_index' => 403],
        ] as $role => $routes) {
            $user = $this->user($role);
            $this->client->loginUser($user, 'main', ['2fa_complete' => true]);
            foreach ($routes as $route => $status) {
                $this->client->request('GET', $router->generate($route, ['_locale' => 'de']));
                self::assertResponseStatusCodeSame($status);
            }
            $this->client->request('GET', $router->generate('admin_license_history', ['id' => (string) $this->license->getId(), '_locale' => 'en']));
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'License history');
        }
        $user = $this->user('ROLE_SUPPORT');
        $this->client->loginUser($user, 'main', ['2fa_complete' => true]);
        $this->client->request('GET', '/admin/licenses/'.$this->license->getId().'/actions/pause');
        self::assertResponseIsSuccessful();
        $this->client->submitForm('Aktion ausführen', ['form[reason]' => 'Support incident']);
        self::assertResponseRedirects();
        $this->client->request('GET', '/admin/licenses/'.$this->license->getId().'/actions/reactivate');
        self::assertResponseIsSuccessful();
    }

    private function user(string $role, bool $enabled = true): User
    {
        $user = (new User())->setEmail(bin2hex(random_bytes(5)).'@example.org')->setFirstname('Test')->setLastname($role)->setRoles([$role]);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'test-password-1234'));
        if ($enabled) {
            $user->enableTwoFactor(self::SECRET, ['recovery-code']);
        }
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function passwordLogin(User $user): void
    {
        $this->client->request('GET', '/login');
        $this->client->submitForm('Anmelden', ['_username' => $user->getEmail(), '_password' => 'test-password-1234']);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertResponseRedirects('/2fa');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    private function activate(): array
    {
        $this->client->jsonRequest('POST', '/api/v1/licenses/activate', ['licenseKey' => $this->license->getLicenseKey(), 'product' => 'test-policy', 'tenant' => 'tenant', 'domain' => 'example.org']);
        self::assertResponseIsSuccessful();

        return $this->signer->verify(json_decode($this->client->getResponse()->getContent(), true)['token']);
    }
}
