<?php

declare(strict_types=1);

namespace Cosmira\Conform\Tests;

use Cosmira\Conform\OpenApi;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ResponseValidatorTest extends TestCase
{
    protected OpenApi $api;

    protected function setUp(): void
    {
        $this->api = OpenApi::from(__DIR__.'/fixtures/petstore.yaml');
    }

    public function test_valid_and_invalid_responses(): void
    {
        $request = Request::create('/pets/1', 'GET');

        $ok = new Response(json_encode([
            'id' => 1,
            'name' => 'Rex',
            'status' => 'available',
        ]), 200, ['Content-Type' => 'application/json']);

        $this->assertSame([], $this->api->response($ok, $request));

        $missing = new Response(json_encode(['name' => 'Rex']), 200, ['Content-Type' => 'application/json']);
        $this->assertNotEmpty($this->api->response($missing, $request));
    }

    public function test_status_wildcards_and_default(): void
    {
        $post = Request::create('/pets', 'POST');
        $clientError = new Response(json_encode([
            'code' => 422,
            'message' => 'Invalid',
        ]), 422, ['Content-Type' => 'application/json']);

        $this->assertSame([], $this->api->response($clientError, $post));

        $get = Request::create('/pets', 'GET');
        $serverError = new Response(json_encode([
            'code' => 500,
            'message' => 'Error',
        ]), 500, ['Content-Type' => 'application/json']);

        $this->assertSame([], $this->api->response($serverError, $get));
    }

    public function test_invalid_json(): void
    {
        $request = Request::create('/pets/1', 'GET');
        $response = new Response('{nope', 200, ['Content-Type' => 'application/json']);

        $this->assertNotEmpty($this->api->response($response, $request));
    }

    public function test_unknown_path_is_skipped(): void
    {
        $request = Request::create('/nope', 'GET');
        $response = new Response('{}', 200, ['Content-Type' => 'application/json']);

        $this->assertSame([], $this->api->response($response, $request));
    }
}
