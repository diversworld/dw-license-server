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
        self::assertCount(2, $document['paths']);
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
