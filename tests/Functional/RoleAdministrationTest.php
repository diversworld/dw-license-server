<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Tests\Support\IsolatedWebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class RoleAdministrationTest extends IsolatedWebTestCase
{
    public function testSuperAdministratorCanSaveAccountAndAssignAdministrativeRoles(): void
    {
        $super = $this->user('ROLE_SUPER_ADMIN');
        $target = $this->user('ROLE_VIEWER');
        $this->client->loginUser($super);
        $this->submit($super, ['ROLE_SUPER_ADMIN'], 'Saved super');
        self::assertResponseRedirects();
        $this->em->clear();
        self::assertContains('ROLE_SUPER_ADMIN', $this->em->find(User::class, $super->getId())->getRoles());
        self::assertSame('Saved super', $this->em->find(User::class, $super->getId())->getFirstname());
        $this->submit($target, ['ROLE_SUPER_ADMIN'], 'Promoted');
        self::assertResponseRedirects();
        $this->em->clear();
        self::assertContains('ROLE_SUPER_ADMIN', $this->em->find(User::class, $target->getId())->getRoles());
    }

    public function testAdministratorCannotEscalateOwnRoleWithForgedChoice(): void
    {
        $admin = $this->user();
        $this->client->loginUser($admin);
        $this->submit($admin, ['ROLE_SUPER_ADMIN'], 'Forged');
        self::assertResponseStatusCodeSame(422);
        $this->em->clear();
        $saved = $this->em->find(User::class, $admin->getId());
        self::assertNotContains('ROLE_SUPER_ADMIN', $saved->getRoles());
        self::assertSame('Test', $saved->getFirstname());
    }

    public static function superMutations(): iterable
    {
        yield 'remove privilege' => [['ROLE_VIEWER'], 'Forged'];
        yield 'retain privilege but alter account' => [['ROLE_SUPER_ADMIN'], 'Forged'];
        yield 'add ordinary administrator role' => [['ROLE_SUPER_ADMIN', 'ROLE_ADMIN'], 'Forged'];
    }

    #[DataProvider('superMutations')]
    public function testAdministratorCannotMutateSuperAccountViaDirectPost(array $roles, string $firstname): void
    {
        $super = $this->user('ROLE_SUPER_ADMIN');
        $admin = $this->user();
        $this->client->loginUser($super);
        $form = $this->editForm($super);
        $values = $form->getPhpValues();
        $values['User']['roles'] = $roles;
        $values['User']['firstname'] = $firstname;
        $values['User']['active'] = false;
        $this->client->loginUser($admin);
        $this->client->request('POST', $form->getUri(), $values, server: ['HTTP_REFERER' => $form->getUri()]);
        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        $saved = $this->em->find(User::class, $super->getId());
        self::assertSame(['ROLE_SUPER_ADMIN', 'ROLE_USER'], $saved->getRoles());
        self::assertSame('Test', $saved->getFirstname());
        self::assertTrue($saved->isActive());
    }

    public function testAdministratorCanAssignSupportWithoutEscalation(): void
    {
        $admin = $this->user();
        $target = $this->user('ROLE_VIEWER');
        $this->client->loginUser($admin);
        $this->submit($target, ['ROLE_SUPPORT'], 'Support');
        self::assertResponseRedirects();
        $this->em->clear();
        self::assertSame(['ROLE_SUPPORT', 'ROLE_USER'], $this->em->find(User::class, $target->getId())->getRoles());
    }

    private function submit(User $user, array $roles, string $firstname): void
    {
        $form = $this->editForm($user);
        $values = $form->getPhpValues();
        $values['User']['roles'] = $roles;
        $values['User']['firstname'] = $firstname;
        $this->client->request('POST', $form->getUri(), $values, server: ['HTTP_REFERER' => $form->getUri()]);
    }

    private function editForm(User $user): \Symfony\Component\DomCrawler\Form
    {
        $url = static::getContainer()->get('router')->generate('admin_user_edit', ['entityId' => (string) $user->getId()]);
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $crawler->filter('form[name="User"]')->form();
    }
}
