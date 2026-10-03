<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_reminder_dispatch', fields: ['license', 'expiry', 'daysBefore', 'recipient'])]
class ReminderDelivery
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;
    #[ORM\Column(length: 20)]
    private string $status = 'queued';
    #[ORM\Column]
    private int $attempts = 0;
    #[ORM\Column(type: 'json')]
    private array $history = [];
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deliveredAt = null;
    public function __construct(
        #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false)] private License $license,
        #[ORM\Column] private \DateTimeImmutable $expiry,
        #[ORM\Column] private int $daysBefore,
        #[ORM\Column(length: 255)] private string $recipient,
        #[ORM\Column(length: 2)] private string $locale,
    ) { $this->id = Uuid::v7(); }
    public function getId(): Uuid { return $this->id; }
    public function getLicense(): License { return $this->license; }
    public function getExpiry(): \DateTimeImmutable { return $this->expiry; }
    public function getDaysBefore(): int { return $this->daysBefore; }
    public function getRecipient(): string { return $this->recipient; }
    public function getLocale(): string { return $this->locale; }
    public function getStatus(): string { return $this->status; }
    public function getAttempts(): int { return $this->attempts; }
    public function getHistory(): array { return $this->history; }
    public function record(string $status, ?string $errorClass = null): void
    {
        $this->status = $status;
        if ($status !== 'cancelled') { ++$this->attempts; }
        $this->history[] = ['at' => gmdate(DATE_ATOM), 'status' => $status, 'errorClass' => $errorClass];
        if ($status === 'sent') { $this->deliveredAt = new \DateTimeImmutable(); }
    }
}
