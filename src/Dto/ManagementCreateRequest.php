<?php
namespace App\Dto;
use Symfony\Component\Validator\Constraints as Assert;
#[\OpenApi\Attributes\Schema(required: ['product', 'reason'], oneOf: [new \OpenApi\Attributes\Schema(required: ['expiresAt']), new \OpenApi\Attributes\Schema(required: ['plan'])])]
final readonly class ManagementCreateRequest
{
    public function __construct(
        #[Assert\NotBlank] #[Assert\Length(max: 255)] public string $product = '',
        #[\OpenApi\Attributes\Property(description: 'Future expiry for custom issuance; omit when selecting a plan.', type: 'string', format: 'date-time')] #[Assert\DateTime(format: 'Y-m-d\TH:i:sP')] public string $expiresAt = '',
        #[Assert\Choice(choices: ['online', 'offline'])] public string $mode = 'online',
        #[Assert\All([new Assert\Type('string'), new Assert\NotBlank()])] public array $features = [],
        #[Assert\Positive] #[Assert\LessThanOrEqual(10000)] public int $maxDomains = 1,
        #[Assert\NotBlank] #[Assert\Length(min: 3, max: 1000)] public string $reason = '',
        #[\OpenApi\Attributes\Property(description: 'Optional active plan UUID; its duration and grants replace custom expiry/features/quotas/installations.', type: 'string', format: 'uuid', nullable: true)] #[Assert\Uuid] public ?string $plan = null,
        public array $quotas = [],
    ) {}
}
