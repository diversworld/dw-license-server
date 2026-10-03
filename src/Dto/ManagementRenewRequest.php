<?php
namespace App\Dto;
use Symfony\Component\Validator\Constraints as Assert;
final readonly class ManagementRenewRequest
{
    public function __construct(
        #[Assert\NotBlank] #[Assert\DateTime(format: 'Y-m-d\TH:i:sP')] public string $expiresAt = '',
        #[Assert\NotBlank] #[Assert\Length(min: 3, max: 1000)] public string $reason = '',
    ) {}
}
