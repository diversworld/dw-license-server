<?php
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_api_idempotency', fields: ['credential', 'idempotencyKey'])]
class ApiOperation
{
    #[ORM\Id] #[ORM\Column(type: 'uuid', unique: true)] private Uuid $id;
    public function __construct(
        #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false)] private ApiToken $credential,
        #[ORM\Column(length: 128)] private string $idempotencyKey,
        #[ORM\Column(length: 64)] private string $bodyHash,
        #[ORM\Column(type: 'json')] private array $response,
    ) { $this->id = Uuid::v7(); }
    public function getId(): Uuid { return $this->id; }
    public function getCredential(): ApiToken { return $this->credential; }
    public function getBodyHash(): string { return $this->bodyHash; }
    public function getResponse(): array { return $this->response; }
}
