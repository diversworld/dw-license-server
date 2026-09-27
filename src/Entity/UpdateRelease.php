<?php

namespace App\Entity;

use App\Repository\UpdateReleaseRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: UpdateReleaseRepository::class)]
class UpdateRelease
{
    #[ORM\Id]
	#[ORM\Column(type: 'uuid', unique: true)]
	private ?Uuid $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $version = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $changelog = null;

    #[ORM\Column(length: 255)]
    private ?string $packageUrl = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTime $releaseDate = null;

    #[ORM\Column(nullable: true)]
    private ?bool $stable = null;

	#[ORM\ManyToOne(inversedBy: 'releases')]
	private ?Product $product = null;

	public function __construct()
	{
		$this->id = Uuid::v7();
		$this->createdAt = new \DateTimeImmutable();
	}


    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getVersion(): ?string
    {
        return $this->version;
    }

    public function setVersion(?string $version): static
    {
        $this->version = $version;

        return $this;
    }

    public function getChangelog(): ?string
    {
        return $this->changelog;
    }

    public function setChangelog(string $changelog): static
    {
        $this->changelog = $changelog;

        return $this;
    }

    public function getPackageUrl(): ?string
    {
        return $this->packageUrl;
    }

    public function setPackageUrl(string $packageUrl): static
    {
        $this->packageUrl = $packageUrl;

        return $this;
    }

    public function getReleaseDate(): ?\DateTime
    {
        return $this->releaseDate;
    }

    public function setReleaseDate(?\DateTime $releaseDate): static
    {
        $this->releaseDate = $releaseDate;

        return $this;
    }

    public function isStable(): ?bool
    {
        return $this->stable;
    }

    public function setStable(?bool $stable): static
    {
        $this->stable = $stable;

        return $this;
    }
}
