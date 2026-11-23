<?php

declare(strict_types=1);

namespace Cosmira\OpenApi\Tests;

use Cosmira\OpenApi\OpenApi;
use Cosmira\OpenApi\Reader\Reader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class MultiFileTest extends TestCase
{
    protected OpenApi $api;

    protected function setUp(): void
    {
        $this->api = OpenApi::from(__DIR__ . '/fixtures/multifile')->basePath('xapi');
    }

    public function test_loads_all_files_into_registry(): void
    {
        $files = $this->api->document()->files();

        $this->assertArrayHasKey('common.yaml', $files);
        $this->assertArrayHasKey('users.yaml', $files);
        $this->assertArrayHasKey('_audit/policy.yaml', $files);
    }

    public function test_merges_paths_from_all_files(): void
    {
        $this->assertNotNull($this->api->match('GET', '/xapi/users'));
        $this->assertNotNull($this->api->match('GET', '/xapi/users/1'));
        $this->assertNotNull($this->api->match('GET', '/xapi/audit/policies'));
    }

    public function test_resolves_external_ref(): void
    {
        $doc = $this->api->document();

        $param = $doc->resolve('common.yaml#/components/parameters/limit');
        $this->assertSame('limit', $param['name']);
        $this->assertSame('query', $param['in']);

        $error = $doc->resolve('common.yaml#/components/schemas/Error');
        $this->assertSame('object', $error['type']);

        $fromAudit = $doc->resolve('../common.yaml#/components/schemas/BooleanAsInteger', '_audit/policy.yaml');
        $this->assertSame([0, 1], $fromAudit['enum']);
    }

    public function test_resolves_local_ref_inside_external_file(): void
    {
        $doc = $this->api->document();
        $response = $doc->resolve('common.yaml#/components/responses/Unauthorized');
        $this->assertSame('Unauthorized', $response['description']);

        // nested #/ ref inside common.yaml must resolve against common.yaml
        $schema = $response['content']['application/json']['schema'];
        $this->assertSame('#/components/schemas/Error', $schema['$ref']);
    }

    public function test_validates_request_with_external_parameter_refs(): void
    {
        $request = Request::create('/xapi/users?start=0&limit=10', 'GET');
        $this->assertSame([], $this->api->request($request));

        $bad = Request::create('/xapi/users?limit=999', 'GET');
        $this->assertNotEmpty($this->api->request($bad));
    }

    public function test_validates_body_with_external_schema_ref(): void
    {
        $ok = Request::create('/xapi/users', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode(['name' => 'Ada', 'active' => 1]));

        $this->assertSame([], $this->api->request($ok));

        $badActive = Request::create('/xapi/users', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode(['name' => 'Ada', 'active' => 2]));

        $this->assertNotEmpty($this->api->request($badActive));
    }

    public function test_validates_response_with_external_ref(): void
    {
        $request = Request::create('/xapi/users/1', 'GET');
        $request->attributes->set('_route_params', ['id' => '1']);

        $ok = new Response(json_encode([
            'id' => 1,
            'name' => 'Ada',
            'active' => 1,
        ]), 200, ['Content-Type' => 'application/json']);

        $this->assertSame([], $this->api->response($ok, $request));

        $unauthorized = new Response(json_encode([
            'code' => 'unauthorized',
            'message' => 'Nope',
        ]), 401, ['Content-Type' => 'application/json']);

        $this->assertSame([], $this->api->response($unauthorized, $request));
    }

    public function test_validates_nested_audit_path(): void
    {
        $request = Request::create('/xapi/audit/policies', 'GET');
        $this->assertSame([], $this->api->request($request));

        $response = new Response(json_encode([
            'data' => [
                ['id' => 1, 'title' => 'Default', 'enabled' => 1],
            ],
        ]), 200, ['Content-Type' => 'application/json']);

        $this->assertSame([], $this->api->response($response, $request));
    }

    public function test_file_for_tracks_origin(): void
    {
        $doc = $this->api->document();
        $this->assertSame('common.yaml', $doc->fileFor('common.yaml#/components/schemas/Error'));
        $this->assertSame('common.yaml', $doc->fileFor('../common.yaml#/components/schemas/Error', '_audit/policy.yaml'));
        $this->assertSame('_audit/policy.yaml', $doc->fileFor('#/components/schemas/Policy', '_audit/policy.yaml'));
    }
}
