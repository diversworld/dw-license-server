<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\License;
use App\Entity\LicenseAuditLog;
use App\Entity\User;
use App\Repository\LicenseAuditLogRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class AuditService
{
    public function __construct(
        private readonly LicenseAuditLogRepository $auditRepository,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function log(
        string $eventType,
        string $message,
        ?License $license = null,
        ?User $performedBy = null,
        array $context = [],
    ): LicenseAuditLog {
        $request = $this->requestStack->getCurrentRequest();

        $audit = new LicenseAuditLog();

        $audit
            ->setEventType($eventType)
            ->setMessage($message)
            ->setLicense($license)
            ->setPerformedBy($performedBy)
            ->setContext($context)
            ->setIpAddress($this->getClientIp($request))
            ->setUserAgent($this->getUserAgent($request));

        $this->auditRepository->save($audit);

        return $audit;
    }

    public function licenseCreated(
        License $license,
        ?User $user = null,
        array $context = [],
    ): LicenseAuditLog {
        return $this->log(
            'license.created',
            sprintf(
                'Lizenz %s wurde erstellt',
                $license->getLicenseKey()
            ),
            $license,
            $user,
            $context
        );
    }

    public function licenseUpdated(
        License $license,
        ?User $user = null,
        array $context = [],
    ): LicenseAuditLog {
        return $this->log(
            'license.updated',
            sprintf(
                'Lizenz %s wurde geändert',
                $license->getLicenseKey()
            ),
            $license,
            $user,
            $context
        );
    }

    public function licenseRevoked(
        License $license,
        ?User $user = null,
        array $context = [],
    ): LicenseAuditLog {
        return $this->log(
            'license.revoked',
            sprintf(
                'Lizenz %s wurde gesperrt',
                $license->getLicenseKey()
            ),
            $license,
            $user,
            $context
        );
    }

    public function licenseActivated(
        License $license,
        string $tenant,
        string $domain,
        ?User $user = null,
        array $context = [],
    ): LicenseAuditLog {
        $context = array_merge([
            'tenant' => $tenant,
            'domain' => $domain,
        ], $context);

        return $this->log(
            'license.activated',
            sprintf(
                'Lizenz %s wurde für %s aktiviert',
                $license->getLicenseKey(),
                $domain
            ),
            $license,
            $user,
            $context
        );
    }

    public function licenseValidated(
        License $license,
        string $tenant,
        string $domain,
        array $context = [],
    ): LicenseAuditLog {
        $context = array_merge([
            'tenant' => $tenant,
            'domain' => $domain,
        ], $context);

        return $this->log(
            'license.validated',
            sprintf(
                'Lizenz %s wurde validiert',
                $license->getLicenseKey()
            ),
            $license,
            null,
            $context
        );
    }

    public function installationBlocked(
        License $license,
        string $tenant,
        string $domain,
        ?User $user = null,
        array $context = [],
    ): LicenseAuditLog {
        $context = array_merge([
            'tenant' => $tenant,
            'domain' => $domain,
        ], $context);

        return $this->log(
            'installation.blocked',
            sprintf(
                'Installation %s wurde gesperrt',
                $domain
            ),
            $license,
            $user,
            $context
        );
    }

    public function installationUnblocked(
        License $license,
        string $tenant,
        string $domain,
        ?User $user = null,
        array $context = [],
    ): LicenseAuditLog {
        $context = array_merge([
            'tenant' => $tenant,
            'domain' => $domain,
        ], $context);

        return $this->log(
            'installation.unblocked',
            sprintf(
                'Installation %s wurde entsperrt',
                $domain
            ),
            $license,
            $user,
            $context
        );
    }

    public function adminLogin(
        User $user,
        array $context = [],
    ): LicenseAuditLog {
        return $this->log(
            'admin.login',
            sprintf(
                'Administrator %s hat sich angemeldet',
                $user->getUserIdentifier()
            ),
            null,
            $user,
            $context
        );
    }

    private function getClientIp(?Request $request): ?string
    {
        if (!$request instanceof Request) {
            return null;
        }

        return $request->getClientIp();
    }

    private function getUserAgent(?Request $request): ?string
    {
        if (!$request instanceof Request) {
            return null;
        }

        return $request->headers->get('User-Agent');
    }
}