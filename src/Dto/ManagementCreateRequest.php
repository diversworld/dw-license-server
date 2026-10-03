<?php
namespace App\Dto;
use Symfony\Component\Validator\Constraints as Assert;
final readonly class ManagementCreateRequest
{
    public function __construct(
        #[Assert\NotBlank] #[Assert\Length(max: 255)] public string $product = '',
        #[Assert\NotBlank] #[Assert\DateTime(format: 'Y-m-d\TH:i:sP')] public string $expiresAt = '',
        #[Assert\Choice(choices: ['online', 'offline'])] public string $mode = 'online',
        #[Assert\All([new Assert\Type('string'), new Assert\NotBlank()])] public array $features = [],
        #[Assert\Positive] #[Assert\LessThanOrEqual(10000)] public int $maxDomains = 1,
        #[Assert\NotBlank] #[Assert\Length(min: 3, max: 1000)] public string $reason = '',
    ) {}
}
