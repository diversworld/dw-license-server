<?php

namespace App\Controller;

use App\Dto\ActivationRequest;
use App\Dto\RefreshRequest;
use App\Service\LicenseManager;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
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
    #[OA\Post(operationId: 'activateInstallation', summary: 'Activate an installation', description: 'Repeated activation for the same tenant and domain returns a fresh token without allocating another slot. Offline licenses require an expiry date.', tags: ['Licenses'], security: [])]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: ActivationRequest::class)))]
    #[OA\Response(response: 200, description: 'Signed license token', content: new OA\JsonContent(ref: '#/components/schemas/TokenResponse'), headers: [new OA\Header(header: 'Cache-Control', schema: new OA\Schema(type: 'string', example: 'no-store'))])]
    #[OA\Response(response: 400, ref: '#/components/responses/BadRequest')]
    #[OA\Response(response: 403, ref: '#/components/responses/Forbidden')]
    #[OA\Response(response: 409, ref: '#/components/responses/Conflict')]
    #[OA\Response(response: 410, ref: '#/components/responses/Gone')]
    #[OA\Response(response: 415, ref: '#/components/responses/UnsupportedMediaType')]
    #[OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntity')]
    #[OA\Response(response: 429, ref: '#/components/responses/TooManyRequests')]
    #[OA\Response(response: 503, ref: '#/components/responses/ServiceUnavailable')]
    public function activate(#[MapRequestPayload(acceptFormat: 'json')] ActivationRequest $request): JsonResponse
    {
        return $this->json(['token' => $this->manager->activate($request)], headers: ['Cache-Control' => 'no-store']);
    }

    #[Route('/validate', name: 'api_license_validate', methods: ['POST'])]
    #[OA\Post(operationId: 'renewLicenseToken', summary: 'Renew an online license token', description: 'Expired online tokens may be renewed when their signature and installation binding remain valid and the license is usable. Offline tokens cannot be renewed.', tags: ['Licenses'], security: [])]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: RefreshRequest::class)))]
    #[OA\Response(response: 200, description: 'Signed license token', content: new OA\JsonContent(ref: '#/components/schemas/TokenResponse'), headers: [new OA\Header(header: 'Cache-Control', schema: new OA\Schema(type: 'string', example: 'no-store'))])]
    #[OA\Response(response: 400, ref: '#/components/responses/BadRequest')]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    #[OA\Response(response: 403, ref: '#/components/responses/Forbidden')]
    #[OA\Response(response: 410, ref: '#/components/responses/Gone')]
    #[OA\Response(response: 415, ref: '#/components/responses/UnsupportedMediaType')]
    #[OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntity')]
    #[OA\Response(response: 429, ref: '#/components/responses/TooManyRequests')]
    #[OA\Response(response: 503, ref: '#/components/responses/ServiceUnavailable')]
    public function validate(#[MapRequestPayload(acceptFormat: 'json')] RefreshRequest $request): JsonResponse
    {
        return $this->json(['token' => $this->manager->refresh($request)], headers: ['Cache-Control' => 'no-store']);
    }
}
