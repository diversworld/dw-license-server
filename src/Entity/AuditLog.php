<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AuditLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: AuditLogRepository::class)]
#[ORM\Table(name: 'audit_log')]
#[ORM\Index(columns: ['event_type'], name: 'idx_audit_event')]
#[ORM\Index(columns: ['created_at'], name: 'idx_audit_created')]
#[ORM\UniqueConstraint(name: 'uniq_audit_sequence', columns: ['chain_sequence'])]
class AuditLog
{
    #[ORM\Column(options: ['default' => 1])]
    private int $hashVersion = 1;

    #[ORM\Column(nullable: true)]
    private ?int $chainSequence = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $actorIdentity = null;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(length: 100)]
    private string $eventType;

    #[ORM\Column(length: 100)]
    private string $entityType;

    #[ORM\Column(length: 255)]
    private string $entityIdentifier;

    #[ORM\Column(length: 36, nullable: true)]
    private ?string $entityId = null;

    #[ORM\Column(length: 255)]
    private string $message;

    #[ORM\Column(type: Types::JSON)]
    private array $context = [];

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $previousHash = null;

    #[ORM\Column(length: 64)]
    private string $entryHash = '';

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $performedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getHashVersion(): int
    {
        return $this->hashVersion;
    }

    public function setHashVersion(int $hashVersion): self
    {
        $this->hashVersion = $hashVersion;

        return $this;
    }

    public function getChainSequence(): ?int
    {
        return $this->chainSequence;
    }

    public function setChainSequence(?int $chainSequence): self
    {
        $this->chainSequence = $chainSequence;

        return $this;
    }

    public function getActorIdentity(): ?string
    {
        return $this->actorIdentity;
    }

    public function setActorIdentity(?string $actorIdentity): self
    {
        $this->actorIdentity = $actorIdentity;

        return $this;
    }

    public function getId(): Uuid
    {
        return $this->id;
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

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function setEntityType(string $entityType): self
    {
        $this->entityType = $entityType;
        return $this;
    }

    public function getEntityIdentifier(): string
    {
        return $this->entityIdentifier;
    }

    public function setEntityIdentifier(string $entityIdentifier): self
    {
        $this->entityIdentifier = $entityIdentifier;
        return $this;
    }

    public function getEntityId(): ?string
    {
        return $this->entityId;
    }

    public function setEntityId(?string $entityId): self
    {
        $this->entityId = $entityId;
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

    public function getPreviousHash(): ?string
    {
        return $this->previousHash;
    }

    public function setPreviousHash(?string $previousHash): self
    {
        $this->previousHash = $previousHash;
        return $this;
    }

    public function getEntryHash(): string
    {
        return $this->entryHash;
    }

    public function setEntryHash(string $entryHash): self
    {
        $this->entryHash = $entryHash;
        return $this;
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }
}
