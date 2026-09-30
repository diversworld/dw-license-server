<?php

namespace App\Controller;

use App\Dto\ActivationRequest;
use App\Dto\RefreshRequest;
use App\Service\LicenseManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[\Symfony\Component\HttpKernel\Attribute\RateLimit('license_api')]
#[Route('/api/v1/licenses', format: 'json')]
class LicenseApiController extends AbstractController
{
    public function __construct(private readonly LicenseManager $manager)
    {
    }

    #[Route('/activate', name: 'api_license_activate', methods: ['POST'])]
    public function activate(#[MapRequestPayload(acceptFormat: 'json')] ActivationRequest $request): JsonResponse
    {
        return $this->json(['token' => $this->manager->activate($request)], headers: ['Cache-Control' => 'no-store']);
    }

    #[Route('/validate', name: 'api_license_validate', methods: ['POST'])]
    public function validate(#[MapRequestPayload(acceptFormat: 'json')] RefreshRequest $request): JsonResponse
    {
        return $this->json(['token' => $this->manager->refresh($request)], headers: ['Cache-Control' => 'no-store']);
    }
}
