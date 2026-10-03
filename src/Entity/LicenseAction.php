<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
class LicenseAction
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        private License $license,
        #[ORM\Column(length: 20)]
        private string $action,
        #[ORM\Column(type: 'text')]
        private string $reason,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?User $performedBy,
        #[ORM\Column(length: 20)]
        private string $fromStatus,
        #[ORM\Column(length: 20)]
        private string $toStatus,
        #[ORM\Column(nullable: true)]
        private ?\DateTimeImmutable $fromExpiresAt,
        #[ORM\Column(nullable: true)]
        private ?\DateTimeImmutable $toExpiresAt,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $details = null;
    public function getDetails(): array { return $this->details ?? []; }
    public function setDetails(array $details): static { $this->details = $details; return $this; }

    public function getId(): Uuid { return $this->id; }
    public function getLicense(): License { return $this->license; }
    public function getAction(): string { return $this->action; }
    public function getReason(): string { return $this->reason; }
    public function getPerformedBy(): ?User { return $this->performedBy; }
    public function getFromStatus(): string { return $this->fromStatus; }
    public function getToStatus(): string { return $this->toStatus; }
    public function getFromExpiresAt(): ?\DateTimeImmutable { return $this->fromExpiresAt; }
    public function getToExpiresAt(): ?\DateTimeImmutable { return $this->toExpiresAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
