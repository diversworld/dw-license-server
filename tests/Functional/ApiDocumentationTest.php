<?php

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ApiDocumentationTest extends WebTestCase
{
    public function testSwaggerUiAndOpenApiDescribeThePublicV1Contract(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/doc');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#swagger-ui');
        $client->request('GET', '/api/doc.json');
        self::assertResponseIsSuccessful();
        $document = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertStringStartsWith('3.', $document['openapi']);
        self::assertSame('Diversworld License API', $document['info']['title']);
        self::assertCount(4, $document['paths']);
        self::assertSame('bearer', $document['components']['securitySchemes']['apiBearer']['scheme']);
        self::assertArrayHasKey('/api/v1/management/licenses', $document['paths']);
        self::assertArrayHasKey('/api/v1/management/licenses/{id}/renew', $document['paths']);
        $management = $document['paths']['/api/v1/management/licenses']['post'];
        $model = $document['components']['schemas'][basename($management['requestBody']['content']['application/json']['schema']['$ref'])];
        self::assertArrayHasKey('plan', $model['properties']); self::assertArrayHasKey('quotas', $model['properties']);
        self::assertNotContains('expiresAt', $model['required']);
        self::assertSame(['licenseId', 'licenseKey', 'expiresAt'], $management['responses'][200]['content']['application/json']['schema']['required']);
        foreach (['activate' => ['licenseKey', 'product', 'tenant', 'domain'], 'validate' => ['token', 'tenant', 'domain']] as $action => $required) {
            $operation = $document['paths']['/api/v1/licenses/'.$action]['post'];
            self::assertNotEmpty($operation['operationId']);
            self::assertTrue($operation['requestBody']['required']);
            $reference = $operation['requestBody']['content']['application/json']['schema']['$ref'];
            $model = $document['components']['schemas'][basename($reference)];
            self::assertEqualsCanonicalizing($required, $model['required']);
            foreach ([200, 400, 403, 410, 415, 422, 429, 503] as $status) {
                self::assertArrayHasKey($status, $operation['responses']);
            }
            self::assertSame('#/components/schemas/TokenResponse', $operation['responses'][200]['content']['application/json']['schema']['$ref']);
        }
        self::assertArrayHasKey(409, $document['paths']['/api/v1/licenses/activate']['post']['responses']);
        self::assertArrayHasKey(401, $document['paths']['/api/v1/licenses/validate']['post']['responses']);
    }
}
