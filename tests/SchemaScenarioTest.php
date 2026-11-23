<?php

declare(strict_types=1);

namespace Cosmira\OpenApi\Tests;

use Cosmira\OpenApi\Document\Document;
use Cosmira\OpenApi\Validation\SchemaValidator;
use PHPUnit\Framework\TestCase;

class SchemaScenarioTest extends TestCase
{
    protected SchemaValidator $v;
    protected Document $doc;

    protected function setUp(): void
    {
        $this->v = new SchemaValidator;
        $this->doc = new Document([
            'openapi' => '3.0.1',
            'paths' => [],
            'components' => [
                'schemas' => [
                    'Id' => ['type' => 'integer', 'minimum' => 1],
                    'Soft' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['$ref' => '#/components/schemas/Id'],
                            'name' => ['type' => 'string'],
                        ],
                        'required' => ['id'],
                    ],
                ],
            ],
        ]);
    }

    protected function ok(mixed $data, array $schema): void
    {
        $this->assertNull($this->v->validate($data, $schema, $this->doc), json_encode($data));
    }

    protected function assertSchemaInvalid(mixed $data, array $schema): void
    {
        $this->assertNotNull($this->v->validate($data, $schema, $this->doc), json_encode($data));
    }

    public function test_types(): void
    {
        $this->ok('x', ['type' => 'string']);
        $this->assertSchemaInvalid(1, ['type' => 'string']);
        $this->ok(1, ['type' => 'integer']);
        $this->assertSchemaInvalid(1.5, ['type' => 'integer']);
        $this->ok(1.5, ['type' => 'number']);
        $this->ok(true, ['type' => 'boolean']);
        $this->ok(['a' => 1], ['type' => 'object']);
        $this->ok([1, 2], ['type' => 'array']);
        $this->ok(null, ['type' => 'string', 'nullable' => true]);
        $this->assertSchemaInvalid(null, ['type' => 'string']);
    }

    public function test_enum_const(): void
    {
        $this->ok(0, ['enum' => [0, 1, 2]]);
        $this->assertSchemaInvalid(3, ['enum' => [0, 1, 2]]);
        $this->ok('a', ['const' => 'a']);
        $this->assertSchemaInvalid('b', ['const' => 'a']);
    }

    public function test_string_bounds_pattern(): void
    {
        $this->ok('ab', ['type' => 'string', 'minLength' => 2, 'maxLength' => 3]);
        $this->assertSchemaInvalid('a', ['type' => 'string', 'minLength' => 2]);
        $this->assertSchemaInvalid('abcd', ['type' => 'string', 'maxLength' => 3]);
        $this->ok('A1', ['type' => 'string', 'pattern' => '^[A-Z][0-9]$']);
        $this->assertSchemaInvalid('a1', ['type' => 'string', 'pattern' => '^[A-Z][0-9]$']);
    }

    public function test_number_bounds(): void
    {
        $this->ok(5, ['type' => 'integer', 'minimum' => 1, 'maximum' => 10]);
        $this->assertSchemaInvalid(0, ['type' => 'integer', 'minimum' => 1]);
        $this->assertSchemaInvalid(11, ['type' => 'integer', 'maximum' => 10]);
    }

    public function test_object_required_additional(): void
    {
        $schema = [
            'type' => 'object',
            'required' => ['id'],
            'properties' => [
                'id' => ['type' => 'integer'],
                'name' => ['type' => 'string', 'nullable' => true],
            ],
            'additionalProperties' => false,
        ];
        $this->ok(['id' => 1, 'name' => null], $schema);
        $this->assertSchemaInvalid(['name' => 'x'], $schema);
        $this->assertSchemaInvalid(['id' => 1, 'extra' => true], $schema);
    }

    public function test_object_without_type(): void
    {
        $schema = [
            'properties' => [
                'id' => ['type' => 'integer'],
            ],
            'required' => ['id'],
        ];
        $this->ok(['id' => 1], $schema);
        $this->assertSchemaInvalid([], $schema);
    }

    public function test_array_items_bounds(): void
    {
        $schema = [
            'type' => 'array',
            'minItems' => 1,
            'maxItems' => 2,
            'items' => ['type' => 'integer'],
        ];
        $this->ok([1], $schema);
        $this->assertSchemaInvalid([], $schema);
        $this->assertSchemaInvalid([1, 2, 3], $schema);
        $this->assertSchemaInvalid([1, 'x'], $schema);
    }

    public function test_allOf_oneOf_anyOf(): void
    {
        $allOf = [
            'allOf' => [
                ['type' => 'object', 'required' => ['a'], 'properties' => ['a' => ['type' => 'string']]],
                ['type' => 'object', 'required' => ['b'], 'properties' => ['b' => ['type' => 'integer']]],
            ],
        ];
        $this->ok(['a' => 'x', 'b' => 1], $allOf);
        $this->assertSchemaInvalid(['a' => 'x'], $allOf);

        $oneOf = [
            'oneOf' => [
                ['type' => 'string'],
                ['type' => 'integer'],
            ],
        ];
        $this->ok('x', $oneOf);
        $this->assertSchemaInvalid(true, $oneOf);

        $anyOf = [
            'anyOf' => [
                ['type' => 'string', 'minLength' => 3],
                ['type' => 'integer', 'minimum' => 10],
            ],
        ];
        $this->ok('abc', $anyOf);
        $this->ok(10, $anyOf);
        $this->assertSchemaInvalid('ab', $anyOf);
    }

    public function test_ref_resolution(): void
    {
        $this->ok(['id' => 1], ['$ref' => '#/components/schemas/Soft']);
        $this->assertSchemaInvalid(['name' => 'x'], ['$ref' => '#/components/schemas/Soft']);
        $this->assertSchemaInvalid(['id' => 0], ['$ref' => '#/components/schemas/Soft']);
    }

    public function test_formats_used_in_publicapi(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $this->ok($uuid, ['type' => 'string', 'format' => 'uuid']);
        $this->ok($uuid, ['type' => 'string', 'format' => 'guid']);
        $this->assertSchemaInvalid('not-a-guid', ['type' => 'string', 'format' => 'guid']);
        $this->ok('2020-01-02', ['type' => 'string', 'format' => 'date']);
        $this->ok('2020-01-02 15:04:05.123456', ['type' => 'string', 'format' => 'date-time-micro']);
        $this->ok('1700000000', ['type' => 'string', 'format' => 'unixtime']);
        $this->ok('anything', ['type' => 'string', 'format' => 'binary']);
        $this->ok('user@example.com', ['type' => 'string', 'format' => 'email']);
    }

    public function test_additional_properties_schema(): void
    {
        $schema = [
            'type' => 'object',
            'additionalProperties' => ['type' => 'integer'],
        ];
        $this->ok(['a' => 1, 'b' => 2], $schema);
        $this->assertSchemaInvalid(['a' => 'x'], $schema);
    }
}
