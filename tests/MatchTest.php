<?php

declare(strict_types=1);

namespace Cosmira\Conform\Tests;

use Cosmira\Conform\Document\Document;
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

    public function test_exact_path_takes_priority_only_for_its_declared_method(): void
    {
        $api = new OpenApi(new Document([
            'paths' => [
                '/users/{name}' => [
                    'get' => ['operationId' => 'showUser'],
                    'post' => ['operationId' => 'updateUser'],
                ],
                '/users/me' => ['get' => ['operationId' => 'showCurrentUser']],
            ],
        ]));

        $this->assertSame('showCurrentUser', $api->match('GET', '/users/me')['operation']['operationId']);
        $this->assertSame('updateUser', $api->match('POST', '/users/me')['operation']['operationId']);
        $this->assertSame(['name' => 'me'], $api->match('POST', '/users/me')['parameters']);
    }

    public function test_template_matches_literal_text_between_placeholders(): void
    {
        $api = new OpenApi(new Document([
            'paths' => [
                '/files/{name}.{extension}/download' => ['get' => ['operationId' => 'downloadFile']],
            ],
        ]));

        $this->assertSame(
            ['name' => 'report', 'extension' => 'json'],
            $api->match('GET', '/files/report.json/download')['parameters'],
        );
        $this->assertNull($api->match('GET', '/files/reportXjson/download'));
    }
}
