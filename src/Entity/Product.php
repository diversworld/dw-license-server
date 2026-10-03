<?php

namespace App\Entity;

use App\Repository\ProductRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ProductRepository::class)]
class Product implements \App\Archive\ArchivableInterface
{
    use \App\Archive\Archivable;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?Uuid $id = null;

    #[ORM\Column(length: 255, nullable: true, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^[a-z0-9][a-z0-9_-]*$/D')]
    private ?string $slug = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\NotBlank]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 6, nullable: true)]
    private ?string $currentVersion = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $downloadUrl = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $price = null;

    #[ORM\Column(nullable: true)]
    private ?bool $active = true;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\OneToMany(mappedBy: 'product', targetEntity: License::class)]
    private Collection $licenses;

    #[ORM\OneToMany(mappedBy: 'product', targetEntity: UpdateRelease::class)]
    private Collection $releases;

    public function __construct()
    {
        $this->licenses = new ArrayCollection();
        $this->releases = new ArrayCollection();
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function setId(Uuid $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(?string $slug): static
    {
        $this->slug = $slug;

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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getCurrentVersion(): ?string
    {
        return $this->currentVersion;
    }

    public function setCurrentVersion(?string $currentVersion): static
    {
        $this->currentVersion = $currentVersion;

        return $this;
    }

    public function getDownloadUrl(): ?string
    {
        return $this->downloadUrl;
    }

    public function setDownloadUrl(?string $downloadUrl): static
    {
        $this->downloadUrl = $downloadUrl;

        return $this;
    }

    public function getPrice(): ?string
    {
        return $this->price;
    }

    public function setPrice(?string $price): static
    {
        $this->price = $price;

        return $this;
    }

    public function isActive(): ?bool
    {
        return !$this->isArchived() && $this->active;
    }

    public function setActive(?bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
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

    #[ORM\Column(options: ['default' => 86400])]
    #[Assert\Range(min: 60, max: 31536000)]
    private int $tokenLifetimeSeconds = 86400;

    public function getTokenLifetimeSeconds(): int
    {
        return $this->tokenLifetimeSeconds;
    }

    public function setTokenLifetimeSeconds(int $seconds): static
    {
        if ($seconds < 60 || $seconds > 31536000) {
            throw new \InvalidArgumentException('Invalid product token policy.');
        }
        $this->tokenLifetimeSeconds = $seconds;

        return $this;
    }

    #[ORM\Column(options: ['default' => 2592000])]
    #[Assert\Range(min: 0, max: 31536000)]
    private int $gracePeriodSeconds = 2592000;

    public function getGracePeriodSeconds(): int
    {
        return $this->gracePeriodSeconds;
    }

    public function setGracePeriodSeconds(int $seconds): static
    {
        if ($seconds < 0 || $seconds > 31536000) {
            throw new \InvalidArgumentException('Invalid product token policy.');
        }
        $this->gracePeriodSeconds = $seconds;

        return $this;
    }

    public function __toString(): string
    {
        return $this->name ?? $this->slug ?? "";
    }
}
