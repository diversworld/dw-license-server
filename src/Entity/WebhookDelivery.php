<?php
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_webhook_event_endpoint', fields: ['endpoint', 'eventId'])]
class WebhookDelivery
{
    #[ORM\Id] #[ORM\Column(type: 'uuid', unique: true)] private Uuid $id;
    #[ORM\Column(length: 20)] private string $status = 'queued';
    #[ORM\Column] private int $attempts = 0;
    #[ORM\Column(type: 'json')] private array $history = [];
    public function __construct(
        #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false)] private WebhookEndpoint $endpoint,
        #[ORM\Column(type: 'uuid')] private Uuid $eventId,
        #[ORM\Column(type: 'json')] private array $payload,
    ) { $this->id = Uuid::v7(); }
    public function getId(): Uuid { return $this->id; }
    public function getEndpoint(): WebhookEndpoint { return $this->endpoint; }
    public function getEventId(): Uuid { return $this->eventId; }
    public function getPayload(): array { return $this->payload; }
    public function getStatus(): string { return $this->status; }
    public function getAttempts(): int { return $this->attempts; }
    public function getHistory(): array { return $this->history; }
    public function record(string $status, ?int $httpStatus = null, ?string $errorClass = null): void
    {
        $this->status = $status; if ($status !== 'cancelled') { ++$this->attempts; }
        $this->history[] = ['at' => gmdate(DATE_ATOM), 'status' => $status, 'httpStatus' => $httpStatus, 'errorClass' => $errorClass];
    }
}
