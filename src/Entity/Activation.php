<?php

namespace App\Entity;

use App\Repository\ActivationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ActivationRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_activation_license_domain', fields: ['license', 'domain'])]
class Activation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 253)]
    private ?string $domain = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAdress = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $contaoVersion = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $moduleVersion = null;

    #[ORM\Column(nullable: true)]
    private ?bool $active = true;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $activatedAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\ManyToOne(inversedBy: 'activations')]
    private ?License $license = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(int $id): static
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
        $this->domain = strtolower(rtrim(trim($domain), '.'));

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

    public function getActivatedAt(): ?\DateTimeImmutable
    {
        return $this->activatedAt;
    }

    public function setActivatedAt(?\DateTimeImmutable $activatedAt): static
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

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function getLicense(): ?License
    {
        return $this->license;
    }

    public function setLicense(?License $license): static
    {
        $this->license = $license;

        return $this;
    }

    #[ORM\Column(length: 180, options: ['default' => ''])]
    #[Assert\NotBlank]
    #[Assert\Length(max: 180)]
    private string $tenant = '';

    public function getTenant(): string
    {
        return $this->tenant;
    }

    public function setTenant(string $tenant): static
    {
        $this->tenant = $tenant;

        return $this;
    }

}
