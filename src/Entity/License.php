<?php

namespace App\Entity;

use App\Repository\LicenseRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

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
    private string $status = 'active';

    #[ORM\Column]
    private int $maxDomains = 1;

    #[ORM\Column(type: Types::JSON)]
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
    private ?Customer $customer = null;

    #[ORM\ManyToOne(inversedBy: 'licenses')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Product $product = null;

	#[ORM\OneToMany(mappedBy: 'license',targetEntity: Activation::class,orphanRemoval: true)]
	private Collection $activations;

	public function __construct()
	{
		$this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->features = [];
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
	{}
	
	public function isActive(): bool
	{}
	
	public function hasFeature(string $feature): bool
	{}	
}