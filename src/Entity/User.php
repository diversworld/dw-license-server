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
class User implements \Symfony\Component\Security\Core\User\EquatableInterface, UserInterface, PasswordAuthenticatedUserInterface, \Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface, \Scheb\TwoFactorBundle\Model\BackupCodeInterface
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
    #[\Symfony\Component\Validator\Constraints\Choice(choices: \App\Security\RoleCatalog::ALLOWED, multiple: true)]
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

    #[ORM\Column(options: ['default' => true])]
    private bool $globalAccess = true;

    /** @var \Doctrine\Common\Collections\Collection<int, Customer> */
    #[ORM\ManyToMany(targetEntity: Customer::class)]
    #[ORM\JoinTable(name: 'user_customer')]
    private \Doctrine\Common\Collections\Collection $customers;

    public function hasGlobalAccess(): bool { return $this->globalAccess; }
    public function isGlobalAccess(): bool { return $this->globalAccess; }
    public function setGlobalAccess(bool $value): static
    {
        if ($value !== $this->globalAccess) { $this->revokeSessions(); }
        $this->globalAccess = $value;
        return $this;
    }
    public function getCustomers(): \Doctrine\Common\Collections\Collection { return $this->customers; }
    public function addCustomer(Customer $customer): static
    {
        if (!$this->customers->contains($customer)) { $this->customers->add($customer); $this->revokeSessions(); }
        return $this;
    }
    public function removeCustomer(Customer $customer): static
    {
        if ($this->customers->removeElement($customer)) { $this->revokeSessions(); }
        return $this;
    }

    #[\Symfony\Component\Validator\Constraints\Callback]
    public function validateCustomerAccess(\Symfony\Component\Validator\Context\ExecutionContextInterface $context): void
    {
        if (in_array('ROLE_CUSTOMER', $this->roles, true) && ($this->globalAccess || $this->customers->isEmpty())) {
            $context->buildViolation('portal.assignment_required')->atPath('customers')->addViolation();
        }
    }

    public function __construct()
    {
        $this->customers = new \Doctrine\Common\Collections\ArrayCollection();
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
        if ($this->active !== $active) { $this->revokeSessions(); }
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
        if ($this->roles !== $roles) { $this->revokeSessions(); }
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

    public function setPassword(#[\SensitiveParameter] string $password): static
    {
        if ($this->password !== null && $this->password !== $password) { $this->revokeSessions(); }
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

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $totpSecret = null;

    private ?string $decryptedTotpSecret = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $securityVersion = 0;

    public function getSecurityVersion(): int { return $this->securityVersion; }
    public function revokeSessions(): void { ++$this->securityVersion; }
    public function getStoredTotpSecret(): ?string { return $this->totpSecret; }
    public function setStoredTotpSecret(string $stored): void { $this->totpSecret = $stored; }
    public function hydrateTotpSecret(string $plain): void { $this->decryptedTotpSecret = $plain; }

    public function isEqualTo(UserInterface $user): bool
    {
        return $user instanceof self && $this->securityVersion === $user->securityVersion
            && $this->getRoles() === $user->getRoles() && $this->active === $user->active && $this->email === $user->email
            && ($this->password === $user->password || $this->password === hash('crc32c', $user->password ?? ''));
    }

    public function regenerateBackupCodes(array $codes): void
    {
        $this->backupCodeHashes = array_map(static fn (string $code): string => hash('sha256', $code), $codes);
        $this->revokeSessions();
    }

    public function disableTwoFactorForRecovery(): void
    {
        $this->totpSecret = $this->decryptedTotpSecret = null;
        $this->backupCodeHashes = [];
        $this->twoFactorDeclined = false;
        $this->revokeSessions();
    }

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
        return $this->totpSecret === null ? null : new \Scheb\TwoFactorBundle\Model\Totp\TotpConfiguration($this->decryptedTotpSecret ?? $this->totpSecret, 'sha1', 30, 6);
    }

    public function enableTwoFactor(#[\SensitiveParameter] string $secret, #[\SensitiveParameter] array $codes): void
    {
        $this->twoFactorDeclined = false;
        $this->totpSecret = $this->decryptedTotpSecret = $secret;
        $this->revokeSessions();
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
        $data["\0" . self::class . "\0decryptedTotpSecret"] = null;

        $data["\0" . self::class . "\0customers"] = new \Doctrine\Common\Collections\ArrayCollection();
        return $data;
    }
}
