<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_EMAIL', fields: ['email'])]
#[UniqueEntity(fields: ['email'],message: 'Diese E-Mail-Adresse wird bereits verwendet.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface, \Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface, \Scheb\TwoFactorBundle\Model\BackupCodeInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?Uuid $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private ?string $email = null;

    #[ORM\Column(length: 255)]
    private ?string $firstname = null;

    #[ORM\Column(length: 255)]
    private ?string $lastname = null;

    /**
     * @var list<string>
     */
    #[ORM\Column]
    #[\Symfony\Component\Validator\Constraints\Choice(choices: ['ROLE_ADMIN', 'ROLE_SUPPORT', 'ROLE_SALES', 'ROLE_VIEWER', 'ROLE_USER'], multiple: true)]
    private array $roles = [];

    #[ORM\Column]
    private ?string $password = null;

	#[ORM\Column(length: 255, nullable: true)]
	private ?string $street = null;

	#[ORM\Column(length: 20, nullable: true)]
	private ?string $postalCode = null;

	#[ORM\Column(length: 255, nullable: true)]
	private ?string $city = null;

	#[ORM\Column(length: 50, nullable: true)]
	private ?string $mobile = null;

	#[ORM\Column(length: 50, nullable: true)]
	private ?string $phone = null;

    #[ORM\Column(length: 255, nullable: true)]
	private ?string $profileImage = null;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = strtolower($email);

        return $this;
    }

    public function getFirstname(): ?string
    {
        return $this->firstname;
    }

    public function setFirstname(string $firstname): static
    {
        $this->firstname = $firstname;

        return $this;
    }

    public function getLastname(): ?string
    {
        return $this->lastname;
    }

    public function setLastname(string $lastname): static
    {
        $this->lastname = $lastname;

        return $this;
    }

    public function getFullname(): string
    {
        return trim(($this->firstname ?? '') . ' ' . ($this->lastname ?? ''));
    }

    public function __toString(): string
    {
        return $this->getFullname() ?: ($this->email ?? '');
    }

	public function getStreet(): ?string
	{
		return $this->street;
	}

	public function setStreet(?string $street): static
	{
		$this->street = $street;

		return $this;
	}

	public function getPostalCode(): ?string
	{
		return $this->postalCode;
	}

	public function setPostalCode(?string $postalCode): static
	{
		$this->postalCode = $postalCode;

		return $this;
	}

	public function getCity(): ?string
	{
		return $this->city;
	}

	public function setCity(?string $city): static
	{
		$this->city = $city;

		return $this;
	}

	public function getMobile(): ?string
	{
		return $this->mobile;
	}

	public function setMobile(?string $mobile): static
	{
		$this->mobile = $mobile;

		return $this;
	}
	
	public function getPhone(): ?string
	{
		return $this->phone;
	}

	public function setPhone(?string $phone): static
	{
		$this->phone = $phone;

		return $this;
	}

    public function getProfileImage(): ?string
	{
		return $this->profileImage;
	}

	public function setProfileImage(?string $profileImage): static
	{
		$this->profileImage = $profileImage;

		return $this;
	}

	public function getProfileImageUrl(): string
	{
		if ($this->profileImage) {
			return '/uploads/profile/' . $this->profileImage;
		}

		return '/images/Diversworld_Viking.png';
	}
    
    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    /**
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;

        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

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

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $totpSecret = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $twoFactorDeclined = false;

    public function hasDeclinedTwoFactor(): bool
    {
        return $this->twoFactorDeclined;
    }

    public function declineTwoFactor(): void
    {
        if ($this->isTotpAuthenticationEnabled()) {
            throw new \LogicException('An active authenticator cannot be declined.');
        }
        $this->twoFactorDeclined = true;
    }

    #[ORM\Column(type: 'json')]
    private array $backupCodeHashes = [];

    public function isTotpAuthenticationEnabled(): bool
    {
        return $this->totpSecret !== null;
    }

    public function getTotpAuthenticationUsername(): string
    {
        return $this->getUserIdentifier();
    }

    public function getTotpAuthenticationConfiguration(): ?\Scheb\TwoFactorBundle\Model\Totp\TotpConfigurationInterface
    {
        return $this->totpSecret === null ? null : new \Scheb\TwoFactorBundle\Model\Totp\TotpConfiguration($this->totpSecret, 'sha1', 30, 6);
    }

    public function enableTwoFactor(string $secret, array $codes): void
    {
        $this->twoFactorDeclined = false;
        $this->totpSecret = $secret;
        $this->backupCodeHashes = array_map(static fn (string $code): string => hash('sha256', $code), $codes);
    }

    public function isBackupCode(string $code): bool
    {
        foreach ($this->backupCodeHashes as $hash) {
            if (hash_equals($hash, hash('sha256', trim($code)))) {
                return true;
            }
        }

        return false;
    }

    public function invalidateBackupCode(string $code): void
    {
        $hash = hash('sha256', trim($code));
        $this->backupCodeHashes = array_values(array_filter($this->backupCodeHashes, static fn (string $candidate): bool => !hash_equals($candidate, $hash)));
    }

    public function eraseCredentials(): void
    {
    }

    public function __serialize(): array
    {
        $data = (array) $this;

        $data["\0" . self::class . "\0password"] = hash(
            'crc32c',
            $this->password ?? ''
        );

        $data["\0" . self::class . "\0totpSecret"] = null;
        $data["\0" . self::class . "\0backupCodeHashes"] = [];

        return $data;
    }
}
