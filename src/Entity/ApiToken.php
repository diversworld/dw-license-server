<?php

namespace App\Entity;

use App\Repository\ApiTokenRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ApiTokenRepository::class)]
class ApiToken
{
    #[ORM\Id]
	#[ORM\Column(type: 'uuid', unique: true)]
	private ?Uuid $id = null;

    #[ORM\Column(length: 255)]
    private ?string $token = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(nullable: true)]
    private ?bool $active = null;

    #[ORM\Column]
    private ?\DateTime $ceratedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTime $updatedAt = null;

	#[ORM\ManyToOne]
	private ?Customer $customer = null;
	
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

    public function getToken(): ?string
    {
        return $this->token;
    }

    public function setToken(string $token): static
    {
        $this->token = $token;

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

    public function getCeratedAt(): ?\DateTime
    {
        return $this->ceratedAt;
    }

    public function setCeratedAt(\DateTime $ceratedAt): static
    {
        $this->ceratedAt = $ceratedAt;

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
	
	public function isExpired(): bool
	{}
}
