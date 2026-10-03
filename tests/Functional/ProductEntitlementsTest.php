<?php
namespace App\Tests\Functional;
use App\Entity\{License, LicensePlan};
use App\Service\ProductEntitlements;
use App\Tests\Support\IsolatedWebTestCase;
final class ProductEntitlementsTest extends IsolatedWebTestCase
{
    private function plan(): LicensePlan
    {
        $base = $this->license(); $product = $base->getProduct()->setAllowedFeatures(['sla', 'reporting'])->setRequiredFeatures(['sla'])->setMaxInstallations(3)->setFeatureQuotas(['reporting' => 100]);
        $plan = (new LicensePlan())->setProduct($product)->setName('Annual Pro')->setDurationDays(365)->setMaxDomains(2)->setFeatures(['sla', 'reporting'])->setQuotas(['reporting' => 50])->setUpdatesAllowed(true);
        $this->em->persist($plan); $this->em->flush(); return $plan;
    }
    public function testPlanSnapshotsSurviveSubsequentProductAndPlanChanges(): void
    {
        $plan = $this->plan(); $customer = $this->em->getRepository(License::class)->findOneBy([])->getCustomer(); $license = (new License())->setCustomer($customer);
        $rights = new ProductEntitlements(); $now = new \DateTimeImmutable('2026-10-03T12:00:00+00:00'); $rights->applyPlan($license, $plan, $now); $this->em->persist($license); $this->em->flush();
        $id = $license->getId(); $snapshot = $license->getPlanSnapshot(); self::assertSame(['reporting' => 50], $license->getQuotas()); self::assertSame('2027-10-03', $license->getExpiresAt()->format('Y-m-d')); self::assertTrue($license->getUpdatesAllowed()); self::assertEquals($license->getExpiresAt(), $license->getUpdatesUntil());
        $plan->setFeatures(['sla'])->setQuotas([])->setMaxDomains(1)->setDurationDays(30)->setUpdatesAllowed(false); $plan->getProduct()->setAllowedFeatures(['sla'])->setFeatureQuotas([])->setMaxInstallations(1); $this->em->flush(); $this->em->clear();
        $license = $this->em->find(License::class, $id); $rights->assertLicense($license); self::assertSame(['sla', 'reporting'], $license->getFeatures()); self::assertSame(2, $license->getMaxDomains()); self::assertSame($snapshot, $license->getPlanSnapshot());
        $license->setNotes('Unrelated edit after a product change'); $this->em->flush(); self::assertSame(['reporting' => 50], $license->getQuotas());
    }
    public function testInvalidFeaturesMissingRequiredRightsAndExcessQuotasAreRejected(): void
    {
        $plan = $this->plan(); $rights = new ProductEntitlements();
        foreach ([[['unknown'], 1, []], [[], 1, []], [['sla'], 4, []], [['sla', 'reporting'], 1, ['reporting' => 101]], [['sla', 'reporting'], 1, []], [['sla'], 1, ['reporting' => 1]]] as [$features, $installations, $quotas]) {
            try { $rights->assertRights($plan->getProduct(), $features, $installations, $quotas); self::fail('Invalid rights accepted'); } catch (\DomainException) { self::assertTrue(true); }
        }
        $plan->setActive(false); $this->expectException(\DomainException::class); $rights->applyPlan(new License(), $plan);
    }
    public function testActualPlanFormCreatesFrozenLicenseAndRequiresCsrf(): void
    {
        $plan = $this->plan(); $customer = $this->em->getRepository(License::class)->findOneBy([])->getCustomer(); $this->client->loginUser($this->user('ROLE_SALES'));
        $crawler = $this->client->request('GET', '/admin/de/licenses/from-plan'); self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Lizenz aus Plan erstellen')->form(); $values = ['form[customer]' => (string) $customer->getId(), 'form[plan]' => (string) $plan->getId(), 'form[mode]' => 'online', 'form[reason]' => 'Annual contract test'];
        $this->client->submit($form, $values + ['form[_token]' => 'forged']); self::assertResponseStatusCodeSame(422); self::assertSame(1, $this->em->getRepository(License::class)->count([]));
        $form = $this->client->request('GET', '/admin/de/licenses/from-plan')->selectButton('Lizenz aus Plan erstellen')->form();
        $this->client->submit($form, $values); self::assertResponseRedirects(); $created = $this->em->getRepository(License::class)->findOneBy(['notes' => 'Annual contract test']); self::assertNotNull($created); self::assertSame((string) $plan->getId(), $created->getPlanSnapshot()['id']); self::assertSame(2, $created->getMaxDomains());
    }
    public function testManagementApiRejectsUnknownFeatureAndSupportsPlanIssuance(): void
    {
        $plan = $this->plan(); $customer = $this->em->getRepository(License::class)->findOneBy([])->getCustomer(); $secret = 'dwapi_'.bin2hex(random_bytes(32)); $credential = (new \App\Entity\ApiToken())->setCustomer($customer)->setToken($secret)->setScopes(['licenses:create'])->setExpiresAt(new \DateTimeImmutable('+1 month'))->setActive(true); $this->em->persist($credential); $this->em->flush();
        $headers = ['HTTP_AUTHORIZATION' => 'Bearer '.$secret, 'HTTP_IDEMPOTENCY_KEY' => 'invalid-rights'];
        $this->client->jsonRequest('POST', '/api/v1/management/licenses', ['product' => 'test', 'expiresAt' => (new \DateTimeImmutable('+1 year'))->format(DATE_ATOM), 'features' => ['unknown'], 'reason' => 'Invalid feature test'], server: $headers); self::assertResponseStatusCodeSame(422); self::assertSame(1, $this->em->getRepository(License::class)->count([]));
        $headers['HTTP_IDEMPOTENCY_KEY'] = 'plan-issued'; $payload = ['product' => 'test', 'plan' => (string) $plan->getId(), 'reason' => 'Plan contract test'];
        $this->client->jsonRequest('POST', '/api/v1/management/licenses', $payload, server: $headers); self::assertResponseIsSuccessful(); $response = json_decode($this->client->getResponse()->getContent(), true); $created = $this->em->find(License::class, \Symfony\Component\Uid\Uuid::fromString($response['licenseId'])); self::assertSame(['reporting' => 50], $created->getQuotas()); self::assertTrue($created->getUpdatesAllowed());
        $this->client->jsonRequest('POST', '/api/v1/management/licenses', $payload, server: $headers); self::assertResponseIsSuccessful(); self::assertSame($response, json_decode($this->client->getResponse()->getContent(), true));
    }
    public function testStructuredQuotaFormRoundTripsAndRejectsDuplicateFeatures(): void
    {
        $factory = static::getContainer()->get(\Symfony\Component\Form\FormFactoryInterface::class);
        $form = $factory->create(\App\Form\QuotaCollectionType::class, ['reporting' => 50], ['csrf_protection' => false]);
        self::assertSame('reporting', $form->createView()->children['reporting']->children['feature']->vars['value']);
        $form->submit([['feature' => 'reporting', 'limit' => '7']]); self::assertTrue($form->isValid()); self::assertSame(['reporting' => 7], $form->getData());
        $duplicate = $factory->create(\App\Form\QuotaCollectionType::class, [], ['csrf_protection' => false]);
        $duplicate->submit([['feature' => 'reporting', 'limit' => '7'], ['feature' => 'reporting', 'limit' => '8']]); self::assertFalse($duplicate->isSynchronized());
    }
    public function testScopedSalesCannotForgeForeignCustomerWhenIssuingAPlan(): void
    {
        $plan = $this->plan(); $own = $this->em->getRepository(License::class)->findOneBy([])->getCustomer();
        $plan->getProduct()->setSlug('own'); $this->em->flush(); $foreign = $this->license()->getCustomer();
        $user = $this->user('ROLE_SALES')->setGlobalAccess(false)->addCustomer($own); $this->em->flush(); $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/admin/de/licenses/from-plan'); self::assertResponseIsSuccessful();
        $options = $crawler->filter('select[name="form[customer]"] option')->extract(['value']); self::assertSame([(string) $own->getId()], $options);
        $values = $crawler->selectButton('Lizenz aus Plan erstellen')->form()->getPhpValues();
        $values['form']['customer'] = (string) $foreign->getId(); $values['form']['plan'] = (string) $plan->getId(); $values['form']['reason'] = 'Forged foreign customer';
        $this->client->request('POST', '/admin/de/licenses/from-plan', $values); self::assertResponseStatusCodeSame(422); self::assertSame(1, $this->em->getRepository(License::class)->count([]));
    }

    public function testProductInitializerDefinesSlaRulesAndPreservesReviewedExistingRules(): void
    {
        $tester = new \Symfony\Component\Console\Tester\CommandTester(static::getContainer()->get(\App\Command\InitializeProductsCommand::class));
        $tester->execute([]); $tester->assertCommandIsSuccessful();
        $product = $this->em->getRepository(\App\Entity\Product::class)->findOneBy(['slug' => 'contao-issue-service-bundle']);
        self::assertSame(['sla'], $product->getAllowedFeatures()); self::assertSame(['sla'], $product->getRequiredFeatures());
        $product->setAllowedFeatures(['sla', 'custom']); $this->em->flush();
        $tester->execute([]); $tester->assertCommandIsSuccessful(); self::assertSame(['sla', 'custom'], $product->getAllowedFeatures());
    }

}
