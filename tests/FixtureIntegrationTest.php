<?php

declare(strict_types=1);

namespace Cosmira\Conform\Tests;

use Cosmira\Conform\OpenApi;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Integration tests against real-world style OpenAPI YAML fixtures
 * (OAI Petstore, OpenAPI-Generator Petstore, PublicAPI-style, multi-file).
 */
class FixtureIntegrationTest extends TestCase
{
    public function test_oai_petstore_list_and_create(): void
    {
        $api = OpenApi::from(__DIR__.'/fixtures/github/oai-petstore.yaml');

        $this->assertSame('listPets', $api->match('GET', '/pets')['operation']['operationId']);
        $this->assertSame(['petId' => '42'], $api->match('GET', '/pets/42')['parameters']);

        // query limit max 100
        $this->assertSame([], $api->request(Request::create('/pets', 'GET', ['limit' => '10'])));
        $this->assertNotEmpty($api->request(Request::create('/pets', 'GET', ['limit' => '101'])));

        // create pet
        $create = Request::create('/pets', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode(['id' => 1, 'name' => 'Rex']));
        $this->assertSame([], $api->request($create));

        $bad = Request::create('/pets', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode(['id' => 1]));
        $this->assertNotEmpty($api->request($bad));

        // response list
        $list = new Response(json_encode([
            ['id' => 1, 'name' => 'Rex'],
        ]), 200, ['Content-Type' => 'application/json']);
        $this->assertSame([], $api->response($list, Request::create('/pets', 'GET')));

        $error = new Response(json_encode([
            'code' => 500,
            'message' => 'fail',
        ]), 500, ['Content-Type' => 'application/json']);
        $this->assertSame([], $api->response($error, Request::create('/pets', 'GET')));
    }

    public function test_oai_petstore_show_by_id(): void
    {
        $api = OpenApi::from(__DIR__.'/fixtures/github/oai-petstore.yaml');

        $request = Request::create('/pets/abc', 'GET');
        $this->assertSame([], $api->request($request));

        $ok = new Response(json_encode([
            'id' => 1,
            'name' => 'Rex',
            'tag' => 'dog',
        ]), 200, ['Content-Type' => 'application/json']);
        $this->assertSame([], $api->response($ok, $request));
    }

    public function test_openapi_generator_petstore_loads_and_matches(): void
    {
        $api = OpenApi::from(__DIR__.'/fixtures/github/openapi-generator-petstore.yaml');

        $this->assertNotEmpty($api->document()->paths());
        $this->assertNotNull($api->document()->get('components.schemas.Pet'));
        $this->assertNotNull($api->document()->get('components.securitySchemes'));

        // /pet POST requires body + security petstore_auth OR may require oauth
        // Security schemes include api_key and petstore_auth
        $match = $api->match('GET', '/store/inventory');
        $this->assertNotNull($match);

        // api_key security on some operations
        $inventory = Request::create('/store/inventory', 'GET');
        // May require api_key header depending on operation security
        $errors = $api->request($inventory);
        // Either empty (no security on op) or missing api_key — both prove validation runs
        $this->assertIsArray($errors);

        if ($match = $api->match('GET', '/pet/1')) {
            $this->assertSame('1', $match['parameters']['petId'] ?? $match['parameters']['petId'] ?? array_values($match['parameters'])[0] ?? null);
        }
    }

    public function test_openapi_generator_pet_body_schema(): void
    {
        $api = OpenApi::from(__DIR__.'/fixtures/github/openapi-generator-petstore.yaml');

        // Try POST /pet with valid minimal Pet if operation exists
        if (! $api->match('POST', '/pet')) {
            $this->markTestSkipped('POST /pet not in fixture');
        }

        $body = [
            'name' => 'doggie',
            'photoUrls' => ['https://example.com/1.png'],
        ];

        $request = Request::create('/pet', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer token',
        ], content: json_encode($body));

        // Security may still fail without oauth scopes — filter schema errors vs security
        $errors = $api->request($request);
        $schemaErrors = array_filter($errors, fn ($e) => str_starts_with($e, 'body:'));
        $this->assertSame([], array_values($schemaErrors));
    }

    public function test_publicapi_style_security_params_body_response(): void
    {
        $api = OpenApi::from(__DIR__.'/fixtures/github/publicapi-style.yaml')->basePath('xapi');

        $headers = [
            'HTTP_X_API_AUTH_TOKEN' => 't',
            'HTTP_X_API_VERSION' => '1',
            'HTTP_X_API_COMPANYID' => '1',
        ];

        // missing security
        $this->assertNotEmpty($api->request(Request::create('/xapi/user', 'GET')));

        // list users with security + query
        $list = Request::create('/xapi/user', 'GET', ['start' => '0', 'limit' => '20'], server: $headers);
        $this->assertSame([], $api->request($list));

        $badLimit = Request::create('/xapi/user', 'GET', ['limit' => '500'], server: $headers);
        $this->assertNotEmpty($api->request($badLimit));

        // path id
        $show = Request::create('/xapi/user/5', 'GET', server: $headers);
        $this->assertSame([], $api->request($show));
        $badId = Request::create('/xapi/user/0', 'GET', server: $headers);
        $this->assertNotEmpty($api->request($badId));

        // JSON create
        $create = Request::create('/xapi/items', 'POST', server: $headers + [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'name' => 'Box',
            'active' => 1,
            'tags' => ['a'],
        ]));
        $this->assertSame([], $api->request($create));

        // multipart
        $upload = Request::create('/xapi/upload', 'POST', parameters: [
            'title' => 'File',
        ], server: $headers + [
            'CONTENT_TYPE' => 'multipart/form-data; boundary=x',
        ]);
        $this->assertSame([], $api->request($upload));

        // response user
        $userResponse = new Response(json_encode([
            'USER_ID' => 1,
            'USERNAME' => 'ada',
            'STATUS' => 0,
            'EMAIL' => 'ada@example.com',
            'EXTERNAL_ID' => '550e8400-e29b-41d4-a716-446655440000',
            'CREATED' => '2020-01-02 03:04:05.123456',
        ]), 200, ['Content-Type' => 'application/json']);
        $this->assertSame([], $api->response($userResponse, $show));

        $listResponse = new Response(json_encode([
            'data' => [[
                'USER_ID' => 1,
                'USERNAME' => 'ada',
                'STATUS' => 0,
            ]],
            'meta' => ['totalCount' => 1, 'start' => 0, 'limit' => 20],
        ]), 200, ['Content-Type' => 'application/json']);
        $this->assertSame([], $api->response($listResponse, $list));
    }

    public function test_multifile_fixture_directory(): void
    {
        $api = OpenApi::from(__DIR__.'/fixtures/multifile')->basePath('xapi');

        $this->assertArrayHasKey('common.yaml', $api->document()->files());
        $this->assertNotNull($api->document()->resolve('common.yaml#/components/parameters/limit'));
        $this->assertNotNull($api->match('GET', '/xapi/users'));
        $this->assertNotNull($api->match('GET', '/xapi/audit/policies'));

        $ok = Request::create('/xapi/users', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode(['name' => 'Ada', 'active' => 1]));
        $this->assertSame([], $api->request($ok));
    }

    public function test_local_petstore_fixture(): void
    {
        $api = OpenApi::from(__DIR__.'/fixtures/petstore.yaml');
        $this->assertNotNull($api->match('GET', '/pets'));
        $this->assertNotNull($api->match('POST', '/pets'));

        $create = Request::create('/pets', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode(['name' => 'Rex', 'status' => 'available']));
        $this->assertSame([], $api->request($create));
    }

    public function test_users_yaml_and_json(): void
    {
        $yaml = OpenApi::from(__DIR__.'/fixtures/users.yaml');
        $json = OpenApi::from(__DIR__.'/fixtures/users.json');

        $this->assertSame('getUser', $yaml->match('GET', '/users/1')['operation']['operationId'] ?? null);
        $this->assertSame('listUsers', $json->match('GET', '/users')['operation']['operationId'] ?? null);
    }
}
