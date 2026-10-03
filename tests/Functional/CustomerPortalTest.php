<?php
namespace App\Tests\Functional;

use App\Entity\{Activation, License, LicenseAction, User};
use App\Service\LicenseSigner;
use App\Tests\Support\IsolatedWebTestCase;
use Symfony\Component\Filesystem\Filesystem;

final class CustomerPortalTest extends IsolatedWebTestCase
{
    private function account(License $license): User
    {
        $user = $this->user('ROLE_CUSTOMER')->setGlobalAccess(false)->addCustomer($license->getCustomer()); $this->em->flush(); return $user;
    }
    private function installation(License $license, string $domain = 'first.example.org'): Activation
    {
        $entry = (new Activation())->setLicense($license)->setTenant('portal-test')->setDomain($domain); $this->em->persist($entry); $this->em->flush(); return $entry;
    }
    public function testPortalListsOnlyAssignedLicensesAndRejectsForeignObjectsAndAdministration(): void
    {
        $own = $this->license(); $own->getProduct()->setSlug('own')->setName('Own Product'); $this->em->flush();
        $foreign = $this->license(); $foreign->getProduct()->setName('Foreign Product'); $this->em->flush();
        $this->client->loginUser($this->account($own));
        $crawler = $this->client->request('GET', '/portal/de'); self::assertResponseIsSuccessful();
        self::assertStringContainsString('Own Product', $crawler->text()); self::assertStringNotContainsString('Foreign Product', $crawler->text());
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control'));
        foreach (['/portal/de/licenses/'.$foreign->getId(), '/portal/de/licenses/'.$foreign->getId().'/download'] as $url) { $this->client->request('GET', $url); self::assertContains($this->client->getResponse()->getStatusCode(), [403, 404]); }
        $this->client->request('GET', '/admin/de'); self::assertResponseStatusCodeSame(403);
    }
    public function testDownloadRequiresCsrfAndOwnInstallationAndReturnsSignedNoStoreFile(): void
    {
        $license = $this->license(); $activation = $this->installation($license);
        $license->getProduct()->setSlug('own-download'); $this->em->flush();
        $foreign = $this->license(); $foreignActivation = $this->installation($foreign, 'foreign.example.org');
        $user = $this->account($license);
        $directory = sys_get_temp_dir().'/portal-keys-'.bin2hex(random_bytes(8)); mkdir($directory, 0700);
        file_put_contents($directory.'/private.key', base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())));
        try {
            $signer = new LicenseSigner($directory.'/private.key'); static::getContainer()->set(LicenseSigner::class, $signer);
            $this->client->loginUser($user); $url = '/portal/de/licenses/'.$license->getId().'/download';
            $this->client->request('POST', $url, ['form' => ['installation' => $activation->getId()]]); self::assertResponseStatusCodeSame(422);
            $crawler = $this->client->request('GET', $url); $form = $crawler->filter('form')->form();
            $values = $form->getPhpValues(); $values['form']['installation'] = $foreignActivation->getId();
            $this->client->request('POST', $url, $values); self::assertResponseStatusCodeSame(422);
            $crawler = $this->client->request('GET', $url); $values = $crawler->filter('form')->form()->getPhpValues();
            $values['form']['installation'] = $activation->getId();
            $this->client->request('POST', $url, $values); self::assertResponseIsSuccessful();
            self::assertSame((string) $license->getId(), $signer->verify(trim($this->client->getResponse()->getContent()))['license_id']);
            self::assertStringContainsString('attachment;', $this->client->getResponse()->headers->get('Content-Disposition'));
            self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control'));
        } finally { (new Filesystem())->remove($directory); }
    }
    public function testDomainChangesRequireReasonAndRespectPersistentLimitsAndHistory(): void
    {
        $license = $this->license(); $activation = $this->installation($license); $user = $this->account($license); $this->client->loginUser($user);
        $url = '/portal/de/installations/'.$activation->getId().'/domain';
        $this->client->request('POST', $url, ['form' => ['domain' => 'new.example.org', 'reason' => 'Moved installation']]); self::assertResponseStatusCodeSame(422);
        foreach (['second.example.org', 'third.example.org'] as $domain) {
            $crawler = $this->client->request('GET', $url); $form = $crawler->filter('form')->form();
            $this->client->submit($form, ['form[domain]' => $domain, 'form[reason]' => 'Moved installation']); self::assertResponseRedirects();
        }
        $crawler = $this->client->request('GET', $url); $this->client->submit($crawler->filter('form')->form(), ['form[domain]' => 'fourth.example.org', 'form[reason]' => 'Moved again']); self::assertResponseStatusCodeSame(422);
        $this->em->clear(); $saved = $this->em->find(Activation::class, $activation->getId()); self::assertSame('third.example.org', $saved->getDomain());
        $history = $this->em->getRepository(LicenseAction::class)->findBy(['license' => $license, 'action' => 'domain_change']); self::assertCount(2, $history);
        self::assertSame('first.example.org', $history[0]->getDetails()['fromDomain']); self::assertSame((string) $user->getId(), (string) $history[0]->getPerformedBy()->getId());
    }
    public function testMandatoryCustomerTwoFactorEnrollmentCannotBeSkipped(): void
    {
        $license = $this->license(); $user = $this->account($license);
        static::getContainer()->set(\App\Security\TwoFactorPolicy::class, new \App\Security\TwoFactorPolicy(static::getContainer()->get(\Symfony\Component\Security\Core\Role\RoleHierarchyInterface::class), ['ROLE_CUSTOMER'], []));
        $this->client->loginUser($user); $this->client->request('GET', '/portal/de'); self::assertResponseRedirects('/security/2fa/setup');
        $this->client->followRedirect(); self::assertResponseIsSuccessful(); self::assertSelectorNotExists('form[action$="/skip"]');
        $stack = static::getContainer()->get(\Symfony\Component\HttpFoundation\RequestStack::class); $stack->push($this->client->getRequest());
        $token = static::getContainer()->get('security.csrf.token_manager')->getToken('two_factor_decision')->getValue(); $stack->getSession()->save(); $stack->pop();
        $this->client->request('POST', '/security/2fa/decision/skip', ['_token' => $token]); self::assertResponseStatusCodeSame(403);
    }

    public function testCustomerLoginGoesToPortalAndMisconfiguredGlobalCustomerIsRejected(): void
    {
        $license = $this->license(); $user = $this->account($license); $user->setPassword(static::getContainer()->get('security.user_password_hasher')->hashPassword($user, 'test-password')); $this->em->flush();
        $crawler = $this->client->request('GET', '/login'); $this->client->submit($crawler->selectButton('Anmelden')->form(), ['_username' => $user->getEmail(), '_password' => 'test-password']); self::assertResponseRedirects('/portal');
        $this->client->followRedirect(); self::assertResponseIsSuccessful();
        $this->em->clear(); $user = $this->em->find(User::class, $user->getId()); $user->setGlobalAccess(true); $this->em->flush();
        $this->client->loginUser($user); $this->client->request('GET', '/portal/de'); self::assertResponseStatusCodeSame(403);
    }
}
