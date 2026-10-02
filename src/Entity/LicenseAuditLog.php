<?php

namespace App\Entity;

use App\Repository\LicenseAuditLogRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: LicenseAuditLogRepository::class)]
#[ORM\Table(name: 'license_audit_log')]
#[ORM\Index(columns: ['created_at'], name: 'idx_audit_created')]
#[ORM\Index(columns: ['event_type'], name: 'idx_audit_event')]
class LicenseAuditLog
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $performedBy = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?License $license = null;

    #[ORM\Column(length: 100)]
    private string $eventType;

    #[ORM\Column(length: 255)]
    private string $message;

    #[ORM\Column(type: 'json')]
    private array $context = [];

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    // Getter / Setter
    public function getId(): Uuid
    {
        return $this->id;
    }   
    public function getPerformedBy(): ?User
    {
        return $this->performedBy;
    }

    public function setPerformedBy(?User $performedBy): self
    {
        $this->performedBy = $performedBy;
        return $this;
    }
    public function getLicense(): ?License
    {
        return $this->license;
    }

    public function setLicense(?License $license): self
    {
        $this->license = $license;
        return $this;
    }
    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function setEventType(string $eventType): self
    {
        $this->eventType = $eventType;
        return $this;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function setMessage(string $message): self
    {
        $this->message = $message;
        return $this;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function setContext(array $context): self
    {
        $this->context = $context;
        return $this;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function setIpAddress(?string $ipAddress): self
    {
        $this->ipAddress = $ipAddress;
        return $this;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(?string $userAgent): self
    {
        $this->userAgent = $userAgent;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}