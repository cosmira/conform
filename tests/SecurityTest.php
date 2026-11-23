<?php

declare(strict_types=1);

namespace Cosmira\Conform\Tests;

use Cosmira\Conform\OpenApi;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class SecurityTest extends TestCase
{
    public function test_requires_api_key_headers(): void
    {
        $api = OpenApi::from(__DIR__.'/fixtures/multifile')->basePath('xapi');

        // multifile fixtures have no root security — add via parse
        $api = OpenApi::parse(<<<'YAML'
openapi: 3.0.1
info: { title: S, version: '1' }
security:
  - authToken: []
    version: []
paths:
  /ping:
    get:
      responses:
        '200':
          description: ok
components:
  securitySchemes:
    authToken:
      type: apiKey
      in: header
      name: X-API-Auth-Token
    version:
      type: apiKey
      in: header
      name: X-API-Version
YAML);

        $bare = Request::create('/ping', 'GET');
        $errors = $api->request($bare);
        $this->assertTrue(collect($errors)->contains(fn ($e) => str_contains($e, 'X-API-Auth-Token')));
        $this->assertTrue(collect($errors)->contains(fn ($e) => str_contains($e, 'X-API-Version')));

        $ok = Request::create('/ping', 'GET', server: [
            'HTTP_X_API_AUTH_TOKEN' => 'secret',
            'HTTP_X_API_VERSION' => '1.0',
        ]);
        $this->assertSame([], $api->request($ok));
    }

    public function test_bearer_http_scheme(): void
    {
        $api = OpenApi::parse(<<<'YAML'
openapi: 3.0.1
info: { title: S, version: '1' }
security:
  - bearer: []
paths:
  /ping:
    get:
      responses:
        '200':
          description: ok
components:
  securitySchemes:
    bearer:
      type: http
      scheme: bearer
YAML);

        $this->assertNotEmpty($api->request(Request::create('/ping', 'GET')));

        $ok = Request::create('/ping', 'GET', server: [
            'HTTP_AUTHORIZATION' => 'Bearer token',
        ]);
        $this->assertSame([], $api->request($ok));
    }

    public function test_operation_can_disable_security(): void
    {
        $api = OpenApi::parse(<<<'YAML'
openapi: 3.0.1
info: { title: S, version: '1' }
security:
  - authToken: []
paths:
  /public:
    get:
      security: []
      responses:
        '200':
          description: ok
components:
  securitySchemes:
    authToken:
      type: apiKey
      in: header
      name: X-Token
YAML);

        $this->assertSame([], $api->request(Request::create('/public', 'GET')));
    }

    public function test_multipart_body_validated(): void
    {
        $api = OpenApi::parse(<<<'YAML'
openapi: 3.0.1
info: { title: S, version: '1' }
paths:
  /upload:
    post:
      requestBody:
        required: true
        content:
          multipart/form-data:
            schema:
              type: object
              required: [title]
              properties:
                title:
                  type: string
                  minLength: 1
              additionalProperties: false
      responses:
        '200':
          description: ok
YAML);

        $request = Request::create('/upload', 'POST', parameters: [
            'title' => 'Doc',
        ], server: [
            'CONTENT_TYPE' => 'multipart/form-data; boundary=----x',
        ]);

        $this->assertSame([], $api->request($request));

        $bad = Request::create('/upload', 'POST', parameters: [], server: [
            'CONTENT_TYPE' => 'multipart/form-data; boundary=----x',
        ]);
        $this->assertNotEmpty($api->request($bad));
    }
}
