<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Internal singleton, changed only by the transactional audit append operation. */
#[ORM\Entity]
#[ORM\Table(name: 'audit_chain_head')]
class AuditChainHead
{
    #[ORM\Id]
    #[ORM\Column]
    private int $id = 1;

    #[ORM\Column]
    private int $sequence = 0;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $entryHash = null;
}
