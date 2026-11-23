<?php

declare(strict_types=1);

namespace Cosmira\Conform\Tests;

use Cosmira\Conform\OpenApi;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class RequestValidatorTest extends TestCase
{
    protected OpenApi $api;

    protected function setUp(): void
    {
        $this->api = OpenApi::from(__DIR__.'/fixtures/petstore.yaml');
    }

    public function test_valid_create_pet(): void
    {
        $this->assertSame([], $this->api->request($this->json('POST', '/pets', [
            'name' => 'Rex',
            'status' => 'available',
        ])));
    }

    public function test_missing_required_field(): void
    {
        $errors = $this->api->request($this->json('POST', '/pets', ['status' => 'available']));

        $this->assertTrue(collect($errors)->contains(fn ($e) => str_contains($e, 'name')));
    }

    public function test_query_and_path_parameters(): void
    {
        $this->assertSame([], $this->api->request(
            Request::create('/pets', 'GET', ['status' => 'available', 'limit' => '10'])
        ));

        $badStatus = Request::create('/pets', 'GET', ['status' => 'flying']);
        $this->assertNotEmpty($this->api->request($badStatus));

        $path = Request::create('/pets/5', 'GET');
        $path->attributes->set('_route_params', ['petId' => '5']);
        $this->assertSame([], $this->api->request($path));

        $badPath = Request::create('/pets/0', 'GET');
        $badPath->attributes->set('_route_params', ['petId' => '0']);
        $this->assertNotEmpty($this->api->request($badPath));
    }

    public function test_base_path(): void
    {
        $api = OpenApi::from(__DIR__.'/fixtures/petstore.yaml')->basePath('api');

        $this->assertSame([], $api->request($this->json('POST', '/api/pets', [
            'name' => 'Rex',
        ])));
    }

    public function test_path_parameters_from_uri(): void
    {
        // No Laravel route attributes — extract from URI template
        $this->assertSame([], $this->api->request(Request::create('/pets/5', 'GET')));
        $this->assertNotEmpty($this->api->request(Request::create('/pets/0', 'GET')));
    }

    public function test_unknown_route_is_skipped(): void
    {
        $this->assertSame([], $this->api->request(Request::create('/unknown', 'GET')));
    }

    protected function json(string $method, string $uri, array $data): Request
    {
        return Request::create($uri, $method, server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode($data));
    }
}
