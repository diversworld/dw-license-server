<?php

namespace App\Tests\Functional;

use App\Entity\{License, LicenseAction};
use App\Tests\Support\IsolatedWebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class LicenseTransitionTest extends IsolatedWebTestCase
{
    public static function transitions(): iterable
    {
        yield 'support resumes a pause' => ['ROLE_SUPPORT', 'suspended', 'reactivate', false, true];
        yield 'support cannot undo revocation by reactivation' => ['ROLE_SUPPORT', 'revoked', 'reactivate', false, false];
        yield 'support has no withdrawal permission' => ['ROLE_SUPPORT', 'revoked', 'withdraw_revocation', false, false];
        yield 'administrator explicitly withdraws revocation' => ['ROLE_ADMIN', 'revoked', 'withdraw_revocation', false, true];
        yield 'super administrator explicitly withdraws revocation' => ['ROLE_SUPER_ADMIN', 'revoked', 'withdraw_revocation', false, true];
        yield 'ordinary reactivation cannot undo revocation for admin either' => ['ROLE_ADMIN', 'revoked', 'reactivate', false, false];
        yield 'sales cannot withdraw revocation' => ['ROLE_SALES', 'revoked', 'withdraw_revocation', false, false];
        yield 'viewer cannot resume pause' => ['ROLE_VIEWER', 'suspended', 'reactivate', false, false];
        yield 'expired pause cannot be resumed' => ['ROLE_SUPPORT', 'suspended', 'reactivate', true, false];
        yield 'expired revoked license must be renewed' => ['ROLE_ADMIN', 'revoked', 'withdraw_revocation', true, false];
        yield 'active license cannot be resumed' => ['ROLE_SUPPORT', 'active', 'reactivate', false, false];
    }

    #[DataProvider('transitions')]
    public function testRoleStateAndExpiryBoundaries(string $role, string $status, string $action, bool $expired, bool $allowed): void
    {
        $license = $this->license()->setStatus($status)->setExpiresAt($expired ? new \DateTimeImmutable('-1 day') : new \DateTimeImmutable('+1 day'));
        $this->em->flush();
        $actor = $this->user($role);
        $this->client->loginUser($actor);
        $this->client->request('GET', '/admin/de/licenses/'.$license->getId().'/actions/'.$action);
        if ($this->client->getResponse()->getStatusCode() !== 403) {
            $this->client->submitForm('Aktion ausführen', ['form[reason]' => 'Approved transition']);
        }
        $this->em->clear();
        $saved = $this->em->find(License::class, $license->getId());
        self::assertSame($allowed ? 'active' : $status, $saved->getStatus());
        $entries = $this->em->getRepository(LicenseAction::class)->findAll();
        self::assertCount($allowed ? 1 : 0, $entries);
        if ($allowed) {
            self::assertSame($action, $entries[0]->getAction());
            self::assertSame($status, $entries[0]->getFromStatus());
            self::assertSame('active', $entries[0]->getToStatus());
            self::assertEquals($actor->getId(), $entries[0]->getPerformedBy()->getId());
            self::assertSame('Approved transition', $entries[0]->getReason());
            self::assertInstanceOf(\DateTimeImmutable::class, $entries[0]->getCreatedAt());
        }
    }

    public function testExpiredRevokedLicenseIsRenewedBeforeExplicitWithdrawal(): void
    {
        $license = $this->license()->setStatus('revoked')->setExpiresAt(new \DateTimeImmutable('-1 day'));
        $this->em->flush();
        $this->client->loginUser($this->user());
        $url = '/admin/de/licenses/'.$license->getId().'/actions/';
        $this->client->request('GET', $url.'renew');
        $this->client->submitForm('Aktion ausführen', ['form[reason]' => 'Extend expired contract', 'form[expiresAt]' => (new \DateTimeImmutable('+1 month'))->format('Y-m-d\TH:i')]);
        self::assertResponseRedirects();
        $this->client->request('GET', $url.'withdraw_revocation');
        $this->client->submitForm('Aktion ausführen', ['form[reason]' => 'Administrative withdrawal']);
        self::assertResponseRedirects();
        $this->em->clear();
        self::assertTrue($this->em->find(License::class, $license->getId())->isActive());
        self::assertCount(2, $this->em->getRepository(LicenseAction::class)->findAll());
    }
}
