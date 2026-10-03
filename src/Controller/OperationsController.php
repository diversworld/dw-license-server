<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\{LicenseSigner, ApiMetrics};
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class OperationsController extends AbstractController
{
    #[Route('/health/live', name: 'health_live', methods: ['GET'])]
    public function live(): JsonResponse { return $this->json(['status' => 'alive'], headers: ['Cache-Control' => 'no-store']); }

    #[Route('/health/ready', name: 'health_ready', methods: ['GET'])]
    public function ready(Connection $connection, LicenseSigner $signer): JsonResponse
    {
        try {
            if ((int) $connection->fetchOne('SELECT 1') !== 1 || strlen($signer->publicKey()) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) { throw new \RuntimeException(); }
            return $this->json(['status' => 'ready'], headers: ['Cache-Control' => 'no-store']);
        } catch (\Throwable) { return $this->json(['status' => 'unavailable'], 503, ['Cache-Control' => 'no-store']); }
    }

    #[Route('/operations/metrics', name: 'operations_metrics', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function metrics(ApiMetrics $metrics): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$user->hasGlobalAccess()) { throw $this->createAccessDeniedException(); }
        return new Response($metrics->exposition(), headers: ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8', 'Cache-Control' => 'no-store']);
    }
}
