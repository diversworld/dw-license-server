<?php
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
#[ORM\Entity]
class WebhookEndpoint
{
    #[ORM\Id] #[ORM\Column(type: 'uuid', unique: true)] private Uuid $id;
    #[ORM\Column] private bool $active = true;
    public function __construct(
        #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false)] private Customer $customer,
        #[ORM\Column(length: 2048)] private string $url,
        #[ORM\Column(length: 255)] private string $secretCiphertext,
        #[ORM\Column(type: 'json')] private array $events,
    ) { $this->id = Uuid::v7(); }
    public function getId(): Uuid { return $this->id; }
    public function getCustomer(): Customer { return $this->customer; }
    public function getUrl(): string { return $this->url; }
    public function getSecretCiphertext(): string { return $this->secretCiphertext; }
    public function getEvents(): array { return $this->events; }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): void { $this->active = $active; }
}
