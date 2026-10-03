<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
class LicensePlan
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    private ?Product $product = null;
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $name = '';
    #[ORM\Column]
    #[Assert\Range(min: 1, max: 36500)]
    private int $durationDays = 365;
    #[ORM\Column]
    #[Assert\Range(min: 1, max: 1000000)]
    private int $maxDomains = 1;
    #[ORM\Column(type: 'json')]
    private array $features = [];
    #[ORM\Column(type: 'json')]
    private array $quotas = [];
    #[ORM\Column(nullable: true)]
    private ?bool $updatesAllowed = null;
    #[ORM\Column]
    private bool $active = true;

    public function __construct() { $this->id = Uuid::v7(); }
    public function getId(): Uuid { return $this->id; }
    public function getProduct(): ?Product { return $this->product; }
    public function setProduct(?Product $product): static { $this->product = $product; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }
    public function getDurationDays(): int { return $this->durationDays; }
    public function setDurationDays(int $days): static { $this->durationDays = $days; return $this; }
    public function getMaxDomains(): int { return $this->maxDomains; }
    public function setMaxDomains(int $limit): static { $this->maxDomains = $limit; return $this; }
    public function getFeatures(): array { return $this->features; }
    public function setFeatures(array $features): static { $this->features = $features; return $this; }
    public function getQuotas(): array { return $this->quotas; }
    public function setQuotas(array $quotas): static { $this->quotas = $quotas; return $this; }
    public function getUpdatesAllowed(): ?bool { return $this->updatesAllowed; }
    public function setUpdatesAllowed(?bool $allowed): static { $this->updatesAllowed = $allowed; return $this; }
    public function isActive(): bool { return $this->active && (bool) $this->product?->isActive(); }
    public function setActive(bool $active): static { $this->active = $active; return $this; }
    public function __toString(): string { return $this->name; }
    #[Assert\Callback]
    public function validateRights(\Symfony\Component\Validator\Context\ExecutionContextInterface $context): void
    {
        if (!$this->product) { return; }
        try { (new \App\Service\ProductEntitlements())->assertRights($this->product, $this->features, $this->maxDomains, $this->quotas); }
        catch (\DomainException $error) { $context->buildViolation($error->getMessage())->atPath('features')->addViolation(); }
    }
}
