<?php

namespace App\Entity;

use App\Repository\LicenseRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: LicenseRepository::class)]
class License
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?Uuid $id = null;

    #[ORM\Column(length: 64, unique: true)]
    private ?string $licenseKey = null;

    #[ORM\Column(length: 20)]
    private string $type = 'single';

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: ['active', 'suspended', 'revoked'])]
    private string $status = 'active';

    #[ORM\Column]
    #[Assert\Positive]
    private int $maxDomains = 1;

    #[ORM\Column(type: Types::JSON)]
    #[Assert\All([new Assert\Type('string'), new Assert\NotBlank()])]
    private array $features = [];

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastValidationAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\ManyToOne(inversedBy: 'licenses')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    private ?Customer $customer = null;

    #[ORM\ManyToOne(inversedBy: 'licenses')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    private ?Product $product = null;

    #[ORM\OneToMany(mappedBy: 'license', targetEntity: Activation::class, orphanRemoval: true)]
    private Collection $activations;

    public function __construct()
    {
        $this->activations = new ArrayCollection();
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->features = [];
        $this->licenseKey = bin2hex(random_bytes(32));
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getLicenseKey(): ?string
    {
        return $this->licenseKey;
    }

    public function setLicenseKey(string $licenseKey): static
    {
        $this->licenseKey = $licenseKey;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getMaxDomains(): int
    {
        return $this->maxDomains;
    }

    public function isExpired(): bool
    {
        return null !== $this->expiresAt && $this->expiresAt <= new \DateTimeImmutable();
    }

    public function isActive(): bool
    {
        return 'active' === $this->status && !$this->isExpired();
    }

    public function hasFeature(string $feature): bool
    {
        return in_array($feature, $this->features, true);
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

    public function getProduct(): ?Product
    {
        return $this->product;
    }

    public function setProduct(?Product $product): static
    {
        $this->product = $product;

        return $this;
    }

    public function setMaxDomains(int $maxDomains): static
    {
        if ($maxDomains < 1) {
            throw new \InvalidArgumentException('Mindestens eine Domain ist erforderlich.');
        }
        $this->maxDomains = $maxDomains;

        return $this;
    }

    public function getFeatures(): array
    {
        return $this->features;
    }

    public function setFeatures(array $features): static
    {
        $this->features = $features;

        return $this;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getLastValidationAt(): ?\DateTimeImmutable
    {
        return $this->lastValidationAt;
    }

    public function setLastValidationAt(?\DateTimeImmutable $lastValidationAt): static
    {
        $this->lastValidationAt = $lastValidationAt;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

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

    public function __toString(): string
    {
        return (string) $this->id;
    }

    #[ORM\Column(length: 10, options: ['default' => 'online'])]
    #[Assert\Choice(choices: ['online', 'offline'])]
    private string $mode = 'online';

    public function getMode(): string
    {
        return $this->mode;
    }

    public function setMode(string $mode): static
    {
        $this->mode = $mode;

        return $this;
    }

}
