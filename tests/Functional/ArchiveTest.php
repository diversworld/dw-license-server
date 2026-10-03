<?php

namespace App\Tests\Functional;

use App\Entity\{Customer, License, Product, User};
use App\Service\LicenseSigner;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\{KernelBrowser, Test\WebTestCase};

class ArchiveTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private License $license;
    private string $keyPath;

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
        $this->keyPath = tempnam(sys_get_temp_dir(), 'archive-key-');
        chmod($this->keyPath, 0600);
        file_put_contents($this->keyPath, base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())));
        static::getContainer()->set(LicenseSigner::class, new LicenseSigner($this->keyPath));
        $customer = (new Customer())->setCompany('Test')->setFirstname('Test')->setLastname('Customer')->setEmail('customer@example.org')->setStreet('Test 1')->setZip('12345')->setCity('Berlin');
        $product = (new Product())->setName('Archive test')->setSlug('archive-test');
        $this->license = (new License())->setCustomer($customer)->setProduct($product);
        foreach ([$customer, $product, $this->license] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        @unlink($this->keyPath);
        parent::tearDown();
    }

    public static function types(): iterable
    {
        yield 'customer' => ['customer'];
        yield 'product' => ['product'];
        yield 'license' => ['license'];
    }

    #[DataProvider('types')]
    public function testArchiveAndRestorePreserveRecordsAndBlockApi(string $type): void
    {
        $entity = match ($type) { 'customer' => $this->license->getCustomer(), 'product' => $this->license->getProduct(), 'license' => $this->license };
        $user = $this->login('ROLE_ADMIN');
        $this->client->jsonRequest('POST', '/api/v1/licenses/activate', $this->activation());
        self::assertResponseIsSuccessful();
        $token = json_decode($this->client->getResponse()->getContent(), true)['token'];
        $this->client->request('GET', '/admin/de/records/'.$type.'/'.$entity->getId().'/archive');
        self::assertResponseIsSuccessful();
        $this->client->submitForm('Aktion ausführen', ['form[reason]' => 'Archived through HTTP']);
        self::assertResponseRedirects();
        $entity = $this->em->find($entity::class, $entity->getId());
        self::assertTrue($entity->isArchived());
        self::assertNotNull($this->em->find($entity::class, $entity->getId()));
        $entry = $this->em->getConnection()->fetchAssociative('SELECT * FROM audit_log WHERE event_type = ?', [$type.'.archived']);
        self::assertSame($user->getId()->toBinary(), $entry['performed_by_id']);
        self::assertSame('Archived through HTTP', $entry['message']);
        $this->client->jsonRequest('POST', '/api/v1/licenses/activate', $this->activation());
        self::assertResponseStatusCodeSame(403);
        $this->client->jsonRequest('POST', '/api/v1/licenses/validate', ['token' => $token, 'tenant' => 'archive-tenant', 'domain' => 'example.org']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/admin/de/records/'.$type.'/'.$entity->getId().'/restore');
        $this->client->submitForm('Aktion ausführen', ['form[reason]' => 'Restored through HTTP']);
        self::assertResponseRedirects();
        $entity = $this->em->find($entity::class, $entity->getId());
        self::assertFalse($entity->isArchived());
        self::assertSame('active', $this->license->getStatus());
        $this->client->jsonRequest('POST', '/api/v1/licenses/validate', ['token' => $token, 'tenant' => 'archive-tenant', 'domain' => 'example.org']);
        self::assertResponseIsSuccessful();
    }

    public function testReadOnlyCannotArchiveAndCsrfIsRequired(): void
    {
        $url = '/admin/de/records/license/'.$this->license->getId().'/archive';
        $this->login('ROLE_VIEWER');
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(403);
        $this->login('ROLE_ADMIN');
        $this->client->request('POST', $url, ['form' => ['reason' => 'Forged request']]);
        self::assertFalse($this->license->isArchived());
        self::assertStringContainsString('CSRF', $this->client->getResponse()->getContent());
    }

    public function testArchivedLicenseCannotBeEditedOrReactivated(): void
    {
        $this->login('ROLE_ADMIN');
        $this->license->archive();
        $this->em->flush();
        $router = static::getContainer()->get('router');
        $this->client->request('GET', $router->generate('admin_license_edit', ['entityId' => (string) $this->license->getId()]));
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/admin/de/licenses/'.$this->license->getId().'/actions/reactivate');
        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->license->isArchived());
    }

    public function testDirectOrmDeletionIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must be archived');
        $this->em->remove($this->license);
    }

    public function testOnlySuperAdministratorsCanManageSuperAdministratorAccounts(): void
    {
        $super = $this->login('ROLE_SUPER_ADMIN');
        $admin = $this->login('ROLE_ADMIN');
        $router = static::getContainer()->get('router');
        $superUrl = $router->generate('admin_user_edit', ['entityId' => (string) $super->getId()]);
        $this->client->request('GET', $superUrl);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', $router->generate('admin_user_edit', ['entityId' => (string) $admin->getId()]));
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('option[value="ROLE_SUPER_ADMIN"]');
        $this->client->loginUser($super);
        $this->client->request('GET', $superUrl);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('option[value="ROLE_SUPER_ADMIN"]');
    }

    public function testRestoringARevokedLicensePreservesItsStatus(): void
    {
        $this->license->setStatus('revoked');
        $this->em->flush();
        $this->login('ROLE_ADMIN');
        foreach (['archive', 'restore'] as $action) {
            $this->client->request('GET', '/admin/de/records/license/'.$this->license->getId().'/'.$action);
            $this->client->submitForm('Aktion ausführen', ['form[reason]' => 'Preserve existing revocation']);
            self::assertResponseRedirects();
        }
        $license = $this->em->find(License::class, $this->license->getId());
        self::assertFalse($license->isArchived());
        self::assertSame('revoked', $license->getStatus());
        $this->client->jsonRequest('POST', '/api/v1/licenses/activate', $this->activation());
        self::assertResponseStatusCodeSame(403);
    }

    private function login(string $role): User
    {
        $user = (new User())->setEmail(bin2hex(random_bytes(6)).'@example.org')->setFirstname('Test')->setLastname('User')->setPassword('test')->setRoles([$role]);
        $user->declineTwoFactor();
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);

        return $user;
    }

    private function activation(): array
    {
        return ['licenseKey' => $this->license->getLicenseKey(), 'product' => 'archive-test', 'tenant' => 'archive-tenant', 'domain' => 'example.org'];
    }
}
