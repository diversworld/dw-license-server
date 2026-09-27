<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class RefreshRequest
{
    public function __construct(
        #[Assert\NotBlank] #[Assert\Length(max: 16384)] public string $token = '',
        #[Assert\NotBlank] #[Assert\Length(max: 180)] public string $tenant = '',
        #[Assert\NotBlank] #[Assert\Hostname(requireTld: true)] #[Assert\Length(max: 253)] public string $domain = '',
    ) {
    }
}
