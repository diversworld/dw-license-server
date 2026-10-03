<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

#[\OpenApi\Attributes\Schema(required: ['licenseKey', 'product', 'tenant', 'domain'])]
final readonly class ActivationRequest
{
    public function __construct(
        #[Assert\NotBlank] #[Assert\Length(exactly: 64)] public string $licenseKey = '',
        #[Assert\NotBlank] #[Assert\Length(max: 255)] public string $product = '',
        #[Assert\NotBlank] #[Assert\Length(max: 180)] public string $tenant = '',
        #[Assert\NotBlank] #[Assert\Hostname(requireTld: true)] #[Assert\Length(max: 253)] public string $domain = '',
    ) {
    }
}
