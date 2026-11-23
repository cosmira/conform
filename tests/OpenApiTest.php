<?php

declare(strict_types=1);

namespace Cosmira\OpenApi\Tests;

use Cosmira\OpenApi\OpenApi;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class OpenApiTest extends TestCase
{
    public function test_end_to_end(): void
    {
        $api = OpenApi::from(__DIR__ . '/fixtures/petstore.yaml');

        $request = Request::create('/pets', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'name' => 'Buddy',
            'status' => 'available',
        ]));

        $this->assertSame([], $api->request($request));

        $response = new Response(json_encode([
            'id' => 10,
            'name' => 'Buddy',
            'status' => 'available',
        ]), 201, ['Content-Type' => 'application/json']);

        $this->assertSame([], $api->response($response, $request));
    }

    public function test_from_directory_and_string(): void
    {
        $this->assertNotNull(OpenApi::from(__DIR__ . '/fixtures')->match('GET', '/pets'));
        $this->assertNotNull(OpenApi::parse(file_get_contents(__DIR__ . '/fixtures/users.yaml'))->match('GET', '/users/1'));
    }

    public function test_base_path_is_immutable(): void
    {
        $api = OpenApi::from(__DIR__ . '/fixtures/petstore.yaml');
        $withBase = $api->basePath('api');

        $this->assertNull($api->match('GET', '/api/pets'));
        $this->assertNotNull($withBase->match('GET', '/api/pets'));
        $this->assertNotNull($api->match('GET', '/pets'));
    }

    public function test_full_document_shape(): void
    {
        $doc = OpenApi::from(__DIR__ . '/fixtures/full.yaml')->document();

        $this->assertSame('petType', $doc->get('components.schemas.Pet.discriminator.propertyName'));
        $this->assertSame('Pet', $doc->get('components.schemas.Pet.xml.name'));
        $this->assertNotNull($doc->get('components.securitySchemes.oauth2.flows.authorizationCode.tokenUrl'));
    }
}
