<?php

namespace App\Archive;

use Symfony\Component\Uid\Uuid;

interface ArchivableInterface
{
    public function getId(): ?Uuid;
    public function getDeletedAt(): ?\DateTimeImmutable;
    public function isArchived(): bool;
    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static;
    public function archive(): void;
    public function restore(): void;
}
