<?php
namespace App\Security;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;
#[\Symfony\Component\DependencyInjection\Attribute\Exclude]
final readonly class ApiPrincipal implements UserInterface
{
    public function __construct(public Uuid $credentialId, public Uuid $customerId, public array $scopes) {}
    public function getUserIdentifier(): string { return 'api:'.$this->credentialId; }
    public function getRoles(): array { return ['ROLE_API']; }
    public function eraseCredentials(): void {}
}
