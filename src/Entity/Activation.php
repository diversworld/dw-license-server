<?php

namespace App\Entity;

use App\Repository\ActivationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ActivationRepository::class)]
class Activation
{
    #[ORM\Id]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $domain = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $ipAdress = null;

    #[ORM\Column(length: 6, nullable: true)]
    private ?string $contaoVersion = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $moduleVersion = null;

    #[ORM\Column(nullable: true)]
    private ?bool $active = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTime $activatedAt = null;

    #[ORM\Column]
    private ?\DateTime $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTime $updatedAt = null;

	#[ORM\ManyToOne(inversedBy: 'activations')]
	private ?License $license = null;

	public function __construct()
	{
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
	
    public function getDomain(): ?string
    {
        return $this->domain;
    }

    public function setDomain(string $domain): static
    {
        $this->domain = $domain;

        return $this;
    }

    public function getIpAdress(): ?string
    {
        return $this->ipAdress;
    }

    public function setIpAdress(?string $ipAdress): static
    {
        $this->ipAdress = $ipAdress;

        return $this;
    }

    public function getContaoVersion(): ?string
    {
        return $this->contaoVersion;
    }

    public function setContaoVersion(?string $contaoVersion): static
    {
        $this->contaoVersion = $contaoVersion;

        return $this;
    }

    public function getModuleVersion(): ?string
    {
        return $this->moduleVersion;
    }

    public function setModuleVersion(?string $moduleVersion): static
    {
        $this->moduleVersion = $moduleVersion;

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

    public function getActivatedAt(): ?\DateTime
    {
        return $this->activatedAt;
    }

    public function setActivatedAt(?\DateTime $activatedAt): static
    {
        $this->activatedAt = $activatedAt;

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

    public function getUpdatedAt(): ?\DateTime
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTime $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
}
