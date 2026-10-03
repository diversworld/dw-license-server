<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use App\Security\TotpSecretCipher;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\{PostLoadEventArgs, PrePersistEventArgs, PreUpdateEventArgs};
use Doctrine\ORM\Events;

#[AsDoctrineListener(event: Events::prePersist, priority: 100)]
#[AsDoctrineListener(event: Events::preUpdate, priority: 100)]
#[AsDoctrineListener(event: Events::postLoad)]
final class TotpSecretEncryption
{
    public function __construct(private readonly TotpSecretCipher $cipher) {}

    public function prePersist(PrePersistEventArgs $args): void { $this->seal($args->getObject()); }
    public function preUpdate(PreUpdateEventArgs $args): void { $this->seal($args->getObject()); }
    public function postLoad(PostLoadEventArgs $args): void
    {
        $user = $args->getObject();
        if ($user instanceof User && $user->getStoredTotpSecret() !== null) { $user->hydrateTotpSecret($this->cipher->decrypt($user->getStoredTotpSecret())); }
    }

    private function seal(object $user): void
    {
        if ($user instanceof User && ($stored = $user->getStoredTotpSecret()) !== null && !str_starts_with($stored, TotpSecretCipher::PREFIX)) {
            $user->setStoredTotpSecret($this->cipher->encrypt($stored));
        }
    }
}
