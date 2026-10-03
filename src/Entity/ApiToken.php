<?php

namespace App\Entity;

use App\Repository\ApiTokenRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ApiTokenRepository::class)]
#[ORM\Index(name: 'idx_api_token_hash', fields: ['tokenHash'])]
class ApiToken
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?Uuid $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $token = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(nullable: true)]
    private ?bool $active = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $ceratedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\ManyToOne]
    private ?Customer $customer = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->ceratedAt = new \DateTimeImmutable();
    }

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $tokenHash = null;
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $scopes = null;
    public function getTokenHash(): ?string { return $this->tokenHash; }
    public function getScopes(): array { return $this->scopes ?? []; }
    public function setScopes(array $scopes): static { $this->scopes = $scopes; return $this; }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function setId(Uuid $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function getToken(): ?string
    {
        return $this->token;
    }

    public function setToken(string $token): static
    {
        $this->tokenHash = hash('sha256', $token);
        $this->token = null;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function isActive(): ?bool
    {
        return $this->active;
    }

    public function setActive(?bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getCeratedAt(): ?\DateTimeImmutable
    {
        return $this->ceratedAt;
    }

    public function setCeratedAt(\DateTimeImmutable $ceratedAt): static
    {
        $this->ceratedAt = $ceratedAt;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function isExpired(): bool
    {
        return null !== $this->expiresAt && $this->expiresAt <= new \DateTimeImmutable();
    }

    public function getCustomer(): ?Customer
    {
        return $this->customer;
    }

    public function setCustomer(?Customer $customer): static
    {
        $this->customer = $customer;

        return $this;
    }

    #[ORM\Column(nullable: true)]

    private ?\DateTimeImmutable $expiresAt = null;

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

}
