<?php

namespace App\Tests\Functional;

use App\Entity\LicensePlan;
use App\Tests\Support\IsolatedWebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class UpdatePermissionChoiceTest extends IsolatedWebTestCase
{
    public static function permissions(): iterable
    {
        yield 'allowed' => [true, 'Updates erlaubt'];
        yield 'denied' => [false, 'Keine Updates'];
        yield 'unspecified' => [null, null];
    }

    #[DataProvider('permissions')]
    public function testListsRenderNullableBooleanPermissions(?bool $permission, ?string $label): void
    {
        $license = $this->license()->setUpdatesAllowed($permission);
        $plan = (new LicensePlan())->setProduct($license->getProduct())->setName('Choice regression')->setUpdatesAllowed($permission);
        $this->em->persist($plan);
        $this->em->flush();
        $this->client->loginUser($this->user());

        foreach (['admin_license_index', 'admin_license_plan_index'] as $route) {
            $this->client->request('GET', static::getContainer()->get('router')->generate($route, ['_locale' => 'de']));
            self::assertResponseIsSuccessful();
            if ($label !== null) {
                self::assertSelectorTextContains('td[data-column="updatesAllowed"]', $label);
            }
        }
    }

    public function testPlanEditPreservesAllThreePermissionValues(): void
    {
        $license = $this->license();
        $plan = (new LicensePlan())->setProduct($license->getProduct())->setName('Choice regression');
        $this->em->persist($plan);
        $this->em->flush();
        $this->client->loginUser($this->user());
        $url = static::getContainer()->get('router')->generate('admin_license_plan_edit', ['_locale' => 'de', 'entityId' => (string) $plan->getId()]);

        foreach ([true, false, null] as $permission) {
            $crawler = $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            $form = $crawler->filter('form[name="LicensePlan"]')->form();
            $choice = $crawler->filter('select[name="LicensePlan[updatesAllowed]"] option')->reduce(
                static fn ($option) => trim($option->text()) === match ($permission) {
                    true => 'Updates erlaubt',
                    false => 'Keine Updates',
                    null => 'Nicht festgelegt',
                }
            );
            self::assertCount(1, $choice);
            $this->client->submit($form, ['LicensePlan[updatesAllowed]' => $choice->attr('value')]);
            self::assertResponseRedirects();
            $this->em->clear();
            $plan = $this->em->find(LicensePlan::class, $plan->getId());
            self::assertNotNull($plan);
            self::assertSame($permission, $plan->getUpdatesAllowed());
        }
    }
}
