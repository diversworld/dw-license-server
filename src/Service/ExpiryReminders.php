<?php

namespace App\Service;

use App\Entity\{License, ReminderDelivery};
use App\Message\SendExpiryReminder;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

final class ExpiryReminders
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly MessageBusInterface $bus,
        #[Autowire('%env(json:REMINDER_DAYS)%')] private readonly array $days,
        #[Autowire('%env(bool:REMINDERS_ENABLED)%')] private readonly bool $enabled) {
        if ($days === [] || count($days) > 12 || array_filter($days, static fn (mixed $day): bool => !is_int($day) || $day < 0 || $day > 365)) { throw new \InvalidArgumentException('Use 1–12 reminder thresholds between 0 and 365 days.'); }
    }
    public function schedule(\DateTimeImmutable $now): int
    {
        if (!$this->enabled) { return 0; }
        $queued = 0;
        foreach ($this->em->getRepository(License::class)->findBy(['status' => 'active', 'deletedAt' => null]) as $license) {
            $queued += $this->em->getConnection()->transactional(function () use ($license, $now): int {
                $this->em->refresh($license, LockMode::PESSIMISTIC_WRITE);
                if (!self::usable($license, $now)) { return 0; }
                $expiry = $license->getExpiresAt();
                $remaining = (int) $now->setTimezone(new \DateTimeZone('UTC'))->setTime(0, 0)->diff($expiry->setTimezone(new \DateTimeZone('UTC'))->setTime(0, 0))->format('%r%a');
                if (!in_array($remaining, $this->days, true)) { return 0; }
                $customer = $license->getCustomer(); $count = 0;
                $recipients = array_unique(array_map(static fn (string $email): string => strtolower(trim($email)), [$customer->getEmail(), ...$customer->getReminderRecipients()]));
                foreach ($recipients as $recipient) {
                    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) { continue; }
                    if ($this->em->getRepository(ReminderDelivery::class)->findOneBy(['license' => $license, 'expiry' => $expiry, 'daysBefore' => $remaining, 'recipient' => $recipient]) !== null) { continue; }
                    $delivery = new ReminderDelivery($license, $expiry, $remaining, $recipient, $customer->getReminderLocale());
                    $this->em->persist($delivery); $this->em->flush();
                    $this->bus->dispatch(new SendExpiryReminder((string) $delivery->getId()));
                    ++$count;
                }
                return $count;
            });
        }
        return $queued;
    }
    public static function usable(License $license, \DateTimeImmutable $now): bool
    {
        return !$license->isArchived() && $license->getStatus() === 'active' && $license->getExpiresAt() !== null && $license->getExpiresAt() > $now
            && $license->getProduct() !== null && $license->getProduct()->isActive() !== false
            && $license->getCustomer() !== null && !$license->getCustomer()->isArchived() && $license->getCustomer()->isActive() !== false;
    }
}
