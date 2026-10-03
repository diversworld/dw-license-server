<?php

namespace App\Tests\Functional;

use App\Entity\Activation;
use App\Entity\Customer;
use App\Entity\License;
use App\Entity\Product;
use App\Entity\User;
use App\Service\LicenseSigner;
use App\Tests\Fixtures\ContaoLicenseValidator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class LicenseFlowTest extends \App\Tests\Support\IsolatedWebTestCase
{
    private License $license;
    private string $keyPath;
    private LicenseSigner $signer;
    private string $email;

    protected function setUp(): void
    {
        parent::setUp();
        $this->keyPath = tempnam(sys_get_temp_dir(), 'license-test-');
        chmod($this->keyPath, 0600);
        file_put_contents($this->keyPath, base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())));
        $this->signer = new LicenseSigner($this->keyPath);
        static::getContainer()->set(LicenseSigner::class, $this->signer);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        // This HTTP suite uses its own schema; MariaDB installation and locking are tested separately.
        $id = bin2hex(random_bytes(8));
        $this->email = $id.'@example.org';
        $customer = (new Customer())->setCompany('Test')->setFirstname('Test')->setLastname('Customer')->setEmail($this->email)->setStreet('Test 1')->setZip('12345')->setCity('Berlin')->setActive(true);
        $product = (new Product())->setAllowedFeatures(['sla'])->setSlug('test-'.$id)->setName('Issue Service')->setActive(true);
        $this->license = (new License())->setCustomer($customer)->setProduct($product)->setFeatures(['sla']);
        $user = (new User())->setEmail($this->email)->setFirstname('Test')->setLastname('Admin')->setRoles(['ROLE_ADMIN']);
        $user->enableTwoFactor('JBSWY3DPEHPK3PXP', []);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'test-password-1234'));
        foreach ([$customer, $product, $this->license, $user] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
    }

    protected function tearDown(): void
    {
        @unlink($this->keyPath);
        parent::tearDown();
    }

    public function testActivationRenewalAndContaoCompatibility(): void
    {
        $token = $this->activate();
        $validator = new ContaoLicenseValidator(base64_encode($this->signer->publicKey()), 'customer-123', 'support.example.org');
        self::assertTrue($validator->validate($token)['enabled']);
        $first = $this->signer->verify($token);
        $second = $this->signer->verify($this->activate());
        self::assertSame($first['activation_id'], $second['activation_id']);
        $this->refresh($token);
        self::assertResponseIsSuccessful();
        $this->post('/activate', ['domain' => 'second.example.org']);
        self::assertResponseStatusCodeSame(409);
    }

    public function testRevokedAndExpiredLicensesAreRejected(): void
    {
        $token = $this->activate();
        $this->updateLicense(fn (License $license) => $license->setStatus('revoked'));
        $this->refresh($token);
        self::assertResponseStatusCodeSame(403);
        $this->updateLicense(fn (License $license) => $license->setStatus('active')->setExpiresAt(new \DateTimeImmutable('-1 second')));
        $this->refresh($token);
        self::assertResponseStatusCodeSame(410);
    }

    public function testTamperedTokenWrongTenantAndDisabledInstallation(): void
    {
        $token = $this->activate();
        $this->refresh($token.'x');
        self::assertResponseStatusCodeSame(401);
        $this->refresh($token, 'wrong-tenant');
        self::assertResponseStatusCodeSame(403);
        $claims = $this->signer->verify($token);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->find(Activation::class, $claims['activation_id'])->setActive(false);
        $em->flush();
        $this->refresh($token);
        self::assertResponseStatusCodeSame(403);
        $this->post('/activate');
        self::assertResponseStatusCodeSame(403);
    }

    public function testProductCustomerAndInputValidation(): void
    {
        $this->post('/activate', ['product' => 'wrong-product']);
        self::assertResponseStatusCodeSame(403);
        $this->post('/activate', ['domain' => 'https://example.org/path']);
        self::assertResponseStatusCodeSame(422);
        $this->updateLicense(fn (License $license) => $license->getCustomer()->setActive(false));
        $this->post('/activate');
        self::assertResponseStatusCodeSame(403);
    }

    public function testOfflineExpiryAndOnlineTokenRecovery(): void
    {
        $this->updateLicense(fn (License $license) => $license->setMode('offline')->setExpiresAt(new \DateTimeImmutable('+10 days')));
        $token = $this->activate();
        $validator = new ContaoLicenseValidator(base64_encode($this->signer->publicKey()), 'customer-123', 'support.example.org');
        self::assertTrue($validator->validate($token)['enabled']);
        $this->refresh($token);
        self::assertResponseStatusCodeSame(403);
        $this->updateLicense(fn (License $license) => $license->setMode('online')->setExpiresAt(null));
        $claims = $this->signer->verify($this->activate());
        $claims['issued_at'] = time() - 40 * 86400;
        $claims['expires_at'] = time() - 1;
        $claims['refresh_after'] = time() - 39 * 86400;
        $this->refresh($this->signer->sign($claims));
        self::assertResponseIsSuccessful();
    }

    public function testAdminLoginAndPages(): void
    {
        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('http://localhost/login');
        $this->client->followRedirect();
        $this->client->submitForm('Anmelden', ['_username' => $this->email, '_password' => 'test-password-1234']);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertResponseRedirects('/2fa');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        $this->client->submitForm('Bestätigen', ['_auth_code' => \OTPHP\TOTP::createFromSecret('JBSWY3DPEHPK3PXP')->now()]);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        $router = static::getContainer()->get('router');
        foreach (['admin_customer_index', 'admin_product_index', 'admin_license_index', 'admin_activation_index', 'admin_user_index', 'admin_customer_new', 'admin_product_new', 'admin_license_new'] as $route) {
            $this->client->request('GET', $router->generate($route));
            self::assertResponseIsSuccessful();
        }
        $this->client->request('GET', '/admin/licenses/'.$this->license->getId().'/issue');
        self::assertResponseIsSuccessful();
        $this->client->submitForm('Lizenz ausstellen', ['installation[tenant]' => 'customer-123', 'installation[domain]' => 'support.example.org']);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('textarea[readonly]');
    }

    public function testAdministratorCanCreateLicense(): void
    {
        $user = static::getContainer()->get(EntityManagerInterface::class)->getRepository(User::class)->findOneBy(['email' => $this->email]);
        $this->client->loginUser($user, 'main', ['2fa_complete' => true]);
        $crawler = $this->client->request('GET', static::getContainer()->get('router')->generate('admin_license_new'));
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="License"]')->form();
        $values = $form->getPhpValues();
        $values['License']['customer'] = (string) $this->license->getCustomer()->getId();
        $values['License']['product'] = (string) $this->license->getProduct()->getId();
        $values['License']['features'] = ['sla'];
        $values['License']['maxDomains'] = 2;
        $values['License']['notes'] = 'created-through-http-'.$this->email;
        $this->client->request('POST', $form->getUri(), $values);
        self::assertResponseRedirects();
        $created = static::getContainer()->get(EntityManagerInterface::class)->getRepository(License::class)->findOneBy(['notes' => 'created-through-http-'.$this->email]);
        self::assertNotNull($created);
        self::assertSame(2, $created->getMaxDomains());
        self::assertTrue($created->hasFeature('sla'));
        self::assertSame(64, strlen($created->getLicenseKey()));
    }

    public function testIssueServiceLicenseWithoutSlaCannotBeIssued(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $product = $em->getRepository(Product::class)->findOneBy(['slug' => 'contao-issue-service-bundle']);
        if (!$product) {
            $product = (new Product())->setAllowedFeatures(['sla'])->setRequiredFeatures(['sla'])->setSlug('contao-issue-service-bundle')->setName('Issue Service')->setActive(true);
            $em->persist($product);
        }
        $this->license->setProduct($product)->setFeatures(['sla']);
        $em->flush();
        $em->getConnection()->update('license', ['features' => '[]'], ['id' => $this->license->getId()->toBinary()]);
        $em->refresh($this->license);
        $this->post('/activate');
        self::assertResponseStatusCodeSame(422);
        $body = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertStringContainsString('product rules', $body['detail']);
        self::assertArrayNotHasKey('token', $body);
    }

    private function activate(): string
    {
        $this->post('/activate');
        self::assertResponseIsSuccessful();

        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['token'];
    }

    private function post(string $path, array $overrides = []): void
    {
        $this->client->jsonRequest('POST', '/api/v1/licenses'.$path, array_replace([
            'licenseKey' => $this->license->getLicenseKey(), 'product' => $this->license->getProduct()->getSlug(),
            'tenant' => 'customer-123', 'domain' => 'support.example.org',
        ], $overrides));
    }

    private function refresh(string $token, string $tenant = 'customer-123'): void
    {
        $this->client->jsonRequest('POST', '/api/v1/licenses/validate', ['token' => $token, 'tenant' => $tenant, 'domain' => 'support.example.org']);
    }

    private function updateLicense(callable $update): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $license = $em->find(License::class, $this->license->getId());
        $update($license);
        $em->flush();
    }
}
