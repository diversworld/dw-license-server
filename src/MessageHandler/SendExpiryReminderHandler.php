<?php

namespace App\MessageHandler;

use App\Entity\ReminderDelivery;
use App\Message\SendExpiryReminder;
use App\Service\ExpiryReminders;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
final class SendExpiryReminderHandler
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly TransportInterface $mailer, private readonly TranslatorInterface $translator,
        #[Autowire('%env(REMINDER_FROM)%')] private readonly string $from,
        #[Autowire('%env(bool:REMINDERS_ENABLED)%')] private readonly bool $enabled) {}
    public function __invoke(SendExpiryReminder $message): void
    {
        $failure = null;
        $this->em->getConnection()->transactional(function () use ($message, &$failure): void {
            $entry = $this->em->find(ReminderDelivery::class, Uuid::fromString($message->deliveryId));
            if ($entry === null) { return; }
            $this->em->refresh($entry, LockMode::PESSIMISTIC_WRITE);
            if (in_array($entry->getStatus(), ['sent', 'cancelled'], true)) { return; }
            $license = $entry->getLicense(); $this->em->refresh($license, LockMode::PESSIMISTIC_WRITE);
            if (!$this->enabled || !ExpiryReminders::usable($license, new \DateTimeImmutable()) || $license->getExpiresAt() != $entry->getExpiry()) {
                $entry->record('cancelled'); $this->em->flush(); return;
            }
            $parameters = ['%product%' => $license->getProduct()->getName(), '%date%' => $entry->getExpiry()->format('Y-m-d'), '%days%' => (string) $entry->getDaysBefore()];
            $email = (new Email())->from($this->from)->to($entry->getRecipient())
                ->subject($this->translator->trans('expiry_reminder.subject', $parameters, 'messages', $entry->getLocale()))
                ->text($this->translator->trans('expiry_reminder.body', $parameters, 'messages', $entry->getLocale()));
            $email->getHeaders()->addIdHeader('Message-ID', 'license-reminder-'.$entry->getId().'@license.invalid');
            try { $this->mailer->send($email); $entry->record('sent'); }
            catch (\Throwable $error) { $entry->record('retry', $error::class); $failure = new \RuntimeException('Simulated or transport delivery failed; retry the stable delivery identifier.'); }
            $this->em->flush();
        });
        if ($failure !== null) { throw $failure; }
    }
}
