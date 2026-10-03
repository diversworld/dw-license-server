<?php

namespace App\Archive;

use Doctrine\ORM\Mapping as ORM;

trait Archivable
{
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function isArchived(): bool
    {
        return $this->deletedAt !== null;
    }

    public function archive(): void
    {
        $this->deletedAt ??= new \DateTimeImmutable();
    }

    public function restore(): void
    {
        $this->deletedAt = null;
    }
}
