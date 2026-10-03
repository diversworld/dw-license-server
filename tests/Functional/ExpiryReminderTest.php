<?php

namespace App\Tests\Functional;

use App\Entity\ReminderDelivery;
use App\Message\SendExpiryReminder;
use App\MessageHandler\SendExpiryReminderHandler;
use App\Service\ExpiryReminders;
use App\Tests\Support\IsolatedWebTestCase;
use Symfony\Component\Mailer\{Envelope, SentMessage};
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

final class SimulatedReminderMailer implements TransportInterface
{
    public array $sent = [];
    public bool $fail = false;
    public function __toString(): string { return "simulated://"; }
    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        if ($this->fail) { throw new \RuntimeException('Fake delivery failure with secret transport data'); }
        $this->sent[] = $message;
        return new SentMessage($message, $envelope ?? Envelope::create($message));
    }
}

final class ExpiryReminderTest extends IsolatedWebTestCase
{
    private function scheduler(): ExpiryReminders { return new ExpiryReminders($this->em, static::getContainer()->get('messenger.default_bus'), [30, 7, 1], true); }
    private function handler(SimulatedReminderMailer $mailer): SendExpiryReminderHandler { return new SendExpiryReminderHandler($this->em, $mailer, static::getContainer()->get('translator'), 'test-sender@example.test', true); }

    public function testSchedulingIsPersistentAndAsyncAndSendsEachRecipientOnceInTheirLanguage(): void
    {
        $license = $this->license()->setExpiresAt(new \DateTimeImmutable('+7 days'));
        $license->getCustomer()->setReminderLocale('fr')->setReminderRecipients(['owner@example.test', 'owner@example.test']); $this->em->flush();
        $scheduler = $this->scheduler(); $mailer = new SimulatedReminderMailer();
        self::assertSame(2, $scheduler->schedule(new \DateTimeImmutable()));
        self::assertSame([], $mailer->sent);
        self::assertSame(0, $scheduler->schedule(new \DateTimeImmutable()));
        $transport = static::getContainer()->get('messenger.transport.async'); self::assertCount(2, $transport->getSent());
        $handler = $this->handler($mailer);
        foreach ($transport->getSent() as $envelope) { $handler($envelope->getMessage()); $handler($envelope->getMessage()); }
        self::assertCount(2, $mailer->sent);
        self::assertStringContainsString('Votre licence', $mailer->sent[0]->getSubject());
        self::assertStringNotContainsString($license->getLicenseKey(), $mailer->sent[0]->getTextBody());
        $this->em->clear();
        foreach ($this->em->getRepository(ReminderDelivery::class)->findAll() as $entry) { self::assertSame('sent', $entry->getStatus()); self::assertSame(1, $entry->getAttempts()); self::assertCount(1, $entry->getHistory()); }
    }

    public function testRetryHistoryIsCommittedAndSensitiveTransportDetailsAreExcluded(): void
    {
        $license = $this->license()->setExpiresAt(new \DateTimeImmutable('+1 day')); $this->em->flush(); $this->scheduler()->schedule(new \DateTimeImmutable());
        $entry = $this->em->getRepository(ReminderDelivery::class)->findOneBy([]);
        $mailer = new SimulatedReminderMailer(); $mailer->fail = true; $handler = $this->handler($mailer);
        try { $handler(new SendExpiryReminder((string) $entry->getId())); self::fail('Failure not retried.'); } catch (\RuntimeException $error) { self::assertStringNotContainsString('secret transport', $error->getMessage()); }
        $this->em->clear(); $entry = $this->em->find(ReminderDelivery::class, $entry->getId());
        self::assertSame('retry', $entry->getStatus()); self::assertSame(1, $entry->getAttempts());
        self::assertStringNotContainsString('secret transport', json_encode($entry->getHistory()));
        $mailer->fail = false; $handler(new SendExpiryReminder((string) $entry->getId()));
        self::assertCount(1, $mailer->sent); self::assertSame(2, $entry->getAttempts()); self::assertSame('sent', $entry->getStatus());
    }

    public function testRenewalRevocationAndArchivalCancelStaleMessages(): void
    {
        $license = $this->license()->setExpiresAt(new \DateTimeImmutable('+7 days')); $this->em->flush();
        $scheduler = $this->scheduler(); self::assertSame(1, $scheduler->schedule(new \DateTimeImmutable()));
        $entry = $this->em->getRepository(ReminderDelivery::class)->findOneBy([]);
        $license->setExpiresAt(new \DateTimeImmutable('+30 days')); $this->em->flush();
        $mailer = new SimulatedReminderMailer(); $handler = $this->handler($mailer); $handler(new SendExpiryReminder((string) $entry->getId()));
        self::assertSame('cancelled', $entry->getStatus()); self::assertSame([], $mailer->sent);
        self::assertSame(1, $scheduler->schedule(new \DateTimeImmutable()));
        $new = $this->em->getRepository(ReminderDelivery::class)->findOneBy(['status' => 'queued']);
        $license->setStatus('revoked'); $this->em->flush(); $handler(new SendExpiryReminder((string) $new->getId()));
        self::assertSame('cancelled', $new->getStatus()); self::assertSame(0, $scheduler->schedule(new \DateTimeImmutable()));
        $license->setStatus('active'); $license->getCustomer()->archive(); $this->em->flush();
        self::assertSame(0, $scheduler->schedule(new \DateTimeImmutable())); self::assertSame([], $mailer->sent);
    }
}
