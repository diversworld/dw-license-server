<?php
namespace App\Controller;
use App\Dto\{ManagementCreateRequest, ManagementRenewRequest};
use App\Entity\License;
use App\Security\ApiPrincipal;
use App\Service\ApiLicenseManagement;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, JsonResponse};
use Symfony\Component\HttpKernel\Attribute\{MapRequestPayload, RateLimit};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
#[Route('/api/v1/management/licenses', format: 'json')]
#[IsGranted('ROLE_API')]
#[RateLimit('license_api')]
final class ManagementApiController extends AbstractController
{
    public function __construct(private readonly ApiLicenseManagement $management) {}
    #[Route('', name: 'api_management_create', methods: ['POST'])]
    #[OA\Post(operationId: 'createCustomerLicense', summary: 'Create a license in the credential customer scope', tags: ['Management'], security: [['apiBearer' => []]])]
    #[OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', maxLength: 128))]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: ManagementCreateRequest::class)))]
    #[OA\Response(response: 200, description: 'License identifier, secret licenseKey and expiry; repeated identical keys return the same response', content: new OA\JsonContent(type: 'object', required: ['licenseId', 'licenseKey', 'expiresAt'], properties: [new OA\Property(property: 'licenseId', type: 'string', format: 'uuid'), new OA\Property(property: 'licenseKey', type: 'string'), new OA\Property(property: 'expiresAt', type: 'string', format: 'date-time')]))]
    #[OA\Response(response: 400, description: 'Missing or invalid idempotency key')]
    #[OA\Response(response: 415, description: 'JSON content type required')]
    #[OA\Response(response: 429, description: 'API rate limit reached')]
    #[OA\Response(response: 401, description: 'Invalid/expired/revoked credential')]
    #[OA\Response(response: 403, description: 'Missing licenses:create scope')]
    #[OA\Response(response: 409, description: 'Conflicting idempotency payload')]
    #[OA\Response(response: 422, description: 'Invalid license data')]
    public function create(#[MapRequestPayload(acceptFormat: 'json')] ManagementCreateRequest $payload, Request $request): JsonResponse
    {
        $actor = $this->getUser(); if (!$actor instanceof ApiPrincipal) { throw $this->createAccessDeniedException(); }
        return $this->json($this->management->create($actor, $payload, $request->headers->get('Idempotency-Key', '')), headers: ['Cache-Control' => 'no-store']);
    }
    #[Route('/{id}/renew', name: 'api_management_renew', methods: ['POST'])]
    #[OA\Post(operationId: 'extendCustomerLicense', summary: 'Extend a license without changing suspension or revocation', tags: ['Management'], security: [['apiBearer' => []]])]
    #[OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', maxLength: 128))]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: ManagementRenewRequest::class)))]
    #[OA\Response(response: 200, description: 'License identifier, new expiry and unchanged status', content: new OA\JsonContent(type: 'object', required: ['licenseId', 'expiresAt', 'status'], properties: [new OA\Property(property: 'licenseId', type: 'string', format: 'uuid'), new OA\Property(property: 'expiresAt', type: 'string', format: 'date-time'), new OA\Property(property: 'status', type: 'string')]))]
    #[OA\Response(response: 400, description: 'Missing or invalid idempotency key')]
    #[OA\Response(response: 415, description: 'JSON content type required')]
    #[OA\Response(response: 429, description: 'API rate limit reached')]
    #[OA\Response(response: 401, description: 'Invalid/expired/revoked credential')]
    #[OA\Response(response: 403, description: 'Scope or customer ownership denied')]
    #[OA\Response(response: 404, description: 'Unknown or foreign license')]
    #[OA\Response(response: 409, description: 'Conflicting idempotency payload')]
    #[OA\Response(response: 422, description: 'Invalid extension')]
    public function renew(License $license, #[MapRequestPayload(acceptFormat: 'json')] ManagementRenewRequest $payload, Request $request): JsonResponse
    {
        $actor = $this->getUser(); if (!$actor instanceof ApiPrincipal) { throw $this->createAccessDeniedException(); }
        return $this->json($this->management->renew($actor, $license, $payload, $request->headers->get('Idempotency-Key', '')), headers: ['Cache-Control' => 'no-store']);
    }
}
