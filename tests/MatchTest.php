<?php

declare(strict_types=1);

namespace Cosmira\Conform\Tests;

use Cosmira\Conform\OpenApi;
use PHPUnit\Framework\TestCase;

class MatchTest extends TestCase
{
    protected OpenApi $api;

    protected function setUp(): void
    {
        $this->api = OpenApi::from(__DIR__.'/fixtures/petstore.yaml');
    }

    public function test_matches_static_and_parameterized_paths(): void
    {
        $this->assertSame('listPets', $this->api->match('GET', '/pets')['operation']['operationId']);
        $this->assertSame('showPet', $this->api->match('GET', '/pets/42')['operation']['operationId']);
        $this->assertSame('petTags', $this->api->match('GET', '/pets/5/tags')['operation']['operationId']);
    }

    public function test_method_is_case_insensitive(): void
    {
        $this->assertNotNull($this->api->match('get', '/pets'));
        $this->assertNotNull($this->api->match('Post', '/pets'));
    }

    public function test_unknown_returns_null(): void
    {
        $this->assertNull($this->api->match('GET', '/unknown'));
        $this->assertNull($this->api->match('DELETE', '/pets'));
    }

    public function test_extracts_path_parameters(): void
    {
        $match = $this->api->match('GET', '/pets/42');
        $this->assertSame(['petId' => '42'], $match['parameters']);
    }

    public function test_base_path(): void
    {
        $api = OpenApi::from(__DIR__.'/fixtures/petstore.yaml')->basePath('api');

        $this->assertSame('listPets', $api->match('GET', '/api/pets')['operation']['operationId']);
        $this->assertSame('showPet', $api->match('GET', '/api/pets/1')['operation']['operationId']);
    }
}
