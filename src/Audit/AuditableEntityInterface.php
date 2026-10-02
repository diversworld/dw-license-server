<?php

declare(strict_types=1);

namespace App\Audit;

interface AuditableEntityInterface
{
    public function getAuditIdentifier(): string;

    public function getAuditType(): string;
}