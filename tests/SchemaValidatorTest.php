<?php

declare(strict_types=1);

namespace Cosmira\Conform\Tests;

use Cosmira\Conform\Document\Document;
use Cosmira\Conform\Reader\Reader;
use Cosmira\Conform\Validation\SchemaValidator;
use PHPUnit\Framework\TestCase;

class SchemaValidatorTest extends TestCase
{
    protected SchemaValidator $validator;

    protected Document $document;

    protected function setUp(): void
    {
        $this->validator = new SchemaValidator;
        $this->document = Reader::file(__DIR__.'/fixtures/petstore.yaml');
    }

    public function test_valid_object(): void
    {
        $schema = [
            'type' => 'object',
            'required' => ['name'],
            'properties' => [
                'name' => ['type' => 'string'],
                'age' => ['type' => 'integer'],
            ],
        ];

        $this->assertNull($this->validator->validate(
            ['name' => 'Rex', 'age' => 3],
            $schema,
            $this->document
        ));
    }

    public function test_missing_required_property(): void
    {
        $schema = [
            'type' => 'object',
            'required' => ['name'],
            'properties' => [
                'name' => ['type' => 'string'],
            ],
        ];

        $error = $this->validator->validate(['age' => 1], $schema, $this->document);

        $this->assertNotNull($error);
        $this->assertStringContainsString('name', $error);
    }

    public function test_additional_properties_false(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
            'additionalProperties' => false,
        ];

        $error = $this->validator->validate(
            ['name' => 'Rex', 'extra' => true],
            $schema,
            $this->document
        );

        $this->assertNotNull($error);
        $this->assertStringContainsString('extra', $error);
    }

    public function test_additional_properties_schema(): void
    {
        $schema = [
            'type' => 'object',
            'additionalProperties' => ['type' => 'integer'],
        ];

        $this->assertNull($this->validator->validate(['a' => 1, 'b' => 2], $schema, $this->document));
        $this->assertNotNull($this->validator->validate(['a' => 'x'], $schema, $this->document));
    }

    public function test_string_constraints(): void
    {
        $schema = [
            'type' => 'string',
            'minLength' => 2,
            'maxLength' => 5,
            'pattern' => '^[a-z]+$',
        ];

        $this->assertNull($this->validator->validate('abc', $schema, $this->document));
        $this->assertNotNull($this->validator->validate('a', $schema, $this->document));
        $this->assertNotNull($this->validator->validate('abcdef', $schema, $this->document));
        $this->assertNotNull($this->validator->validate('ABC', $schema, $this->document));
    }

    public function test_integer_and_number_bounds(): void
    {
        $int = ['type' => 'integer', 'minimum' => 1, 'maximum' => 10];
        $this->assertNull($this->validator->validate(5, $int, $this->document));
        $this->assertNotNull($this->validator->validate(0, $int, $this->document));
        $this->assertNotNull($this->validator->validate(11, $int, $this->document));
        $this->assertNotNull($this->validator->validate(1.5, $int, $this->document));

        $num = ['type' => 'number', 'exclusiveMinimum' => 0, 'exclusiveMaximum' => 10];
        $this->assertNull($this->validator->validate(5.5, $num, $this->document));
        $this->assertNotNull($this->validator->validate(0, $num, $this->document));
        $this->assertNotNull($this->validator->validate(10, $num, $this->document));
    }

    public function test_openapi_30_exclusive_boolean_form(): void
    {
        $schema = [
            'type' => 'number',
            'minimum' => 0,
            'exclusiveMinimum' => true,
            'maximum' => 10,
            'exclusiveMaximum' => true,
        ];

        $this->assertNull($this->validator->validate(5, $schema, $this->document));
        $this->assertNotNull($this->validator->validate(0, $schema, $this->document));
        $this->assertNotNull($this->validator->validate(10, $schema, $this->document));
    }

    public function test_array_items_and_bounds(): void
    {
        $schema = [
            'type' => 'array',
            'minItems' => 1,
            'maxItems' => 3,
            'items' => ['type' => 'string'],
        ];

        $this->assertNull($this->validator->validate(['a', 'b'], $schema, $this->document));
        $this->assertNotNull($this->validator->validate([], $schema, $this->document));
        $this->assertNotNull($this->validator->validate(['a', 'b', 'c', 'd'], $schema, $this->document));
        $this->assertNotNull($this->validator->validate([1], $schema, $this->document));
    }

    public function test_enum_and_const(): void
    {
        $enum = ['type' => 'string', 'enum' => ['a', 'b']];
        $this->assertNull($this->validator->validate('a', $enum, $this->document));
        $this->assertNotNull($this->validator->validate('c', $enum, $this->document));

        $const = ['const' => 42];
        $this->assertNull($this->validator->validate(42, $const, $this->document));
        $this->assertNotNull($this->validator->validate(41, $const, $this->document));
    }

    public function test_nullable(): void
    {
        $schema = ['type' => 'string', 'nullable' => true];

        $this->assertNull($this->validator->validate(null, $schema, $this->document));
        $this->assertNull($this->validator->validate('ok', $schema, $this->document));
        $this->assertNotNull($this->validator->validate(1, $schema, $this->document));
    }

    public function test_formats(): void
    {
        $email = ['type' => 'string', 'format' => 'email'];
        $this->assertNull($this->validator->validate('a@b.co', $email, $this->document));
        $this->assertNotNull($this->validator->validate('nope', $email, $this->document));

        $uuid = ['type' => 'string', 'format' => 'uuid'];
        $this->assertNull($this->validator->validate('550e8400-e29b-41d4-a716-446655440000', $uuid, $this->document));
        $this->assertNotNull($this->validator->validate('not-uuid', $uuid, $this->document));

        $date = ['type' => 'string', 'format' => 'date'];
        $this->assertNull($this->validator->validate('2024-01-15', $date, $this->document));
        $this->assertNotNull($this->validator->validate('01-15-2024', $date, $this->document));

        $dt = ['type' => 'string', 'format' => 'date-time'];
        $this->assertNull($this->validator->validate('2024-01-15T10:00:00Z', $dt, $this->document));

        $uri = ['type' => 'string', 'format' => 'uri'];
        $this->assertNull($this->validator->validate('https://example.com', $uri, $this->document));
        $this->assertNotNull($this->validator->validate('not a url', $uri, $this->document));
    }

    public function test_all_of(): void
    {
        $schema = [
            'allOf' => [
                ['type' => 'object', 'required' => ['a'], 'properties' => ['a' => ['type' => 'string']]],
                ['type' => 'object', 'required' => ['b'], 'properties' => ['b' => ['type' => 'integer']]],
            ],
        ];

        $this->assertNull($this->validator->validate(['a' => 'x', 'b' => 1], $schema, $this->document));
        $this->assertNotNull($this->validator->validate(['a' => 'x'], $schema, $this->document));
    }

    public function test_one_of(): void
    {
        $schema = [
            'oneOf' => [
                ['type' => 'string'],
                ['type' => 'integer'],
            ],
        ];

        $this->assertNull($this->validator->validate('x', $schema, $this->document));
        $this->assertNull($this->validator->validate(1, $schema, $this->document));
        $this->assertNotNull($this->validator->validate(true, $schema, $this->document));
    }

    public function test_any_of(): void
    {
        $schema = [
            'anyOf' => [
                ['type' => 'string', 'minLength' => 5],
                ['type' => 'integer', 'minimum' => 10],
            ],
        ];

        $this->assertNull($this->validator->validate('hello', $schema, $this->document));
        $this->assertNull($this->validator->validate(10, $schema, $this->document));
        $this->assertNotNull($this->validator->validate('hi', $schema, $this->document));
    }

    public function test_not(): void
    {
        $schema = [
            'not' => ['type' => 'string'],
        ];

        $this->assertNull($this->validator->validate(1, $schema, $this->document));
        $this->assertNotNull($this->validator->validate('x', $schema, $this->document));
    }

    public function test_ref_resolution(): void
    {
        $schema = ['$ref' => '#/components/schemas/Error'];

        $this->assertNull($this->validator->validate(
            ['code' => 404, 'message' => 'Not found'],
            $schema,
            $this->document
        ));

        $this->assertNotNull($this->validator->validate(
            ['code' => 404],
            $schema,
            $this->document
        ));
    }

    public function test_nested_ref_via_all_of(): void
    {
        $schema = ['$ref' => '#/components/schemas/Pet'];

        $this->assertNull($this->validator->validate(
            ['id' => 1, 'name' => 'Rex', 'status' => 'available'],
            $schema,
            $this->document
        ));

        $this->assertNotNull($this->validator->validate(
            ['name' => 'Rex'],
            $schema,
            $this->document
        ));
    }

    public function test_boolean_type(): void
    {
        $schema = ['type' => 'boolean'];

        $this->assertNull($this->validator->validate(true, $schema, $this->document));
        $this->assertNull($this->validator->validate(false, $schema, $this->document));
        $this->assertNotNull($this->validator->validate('true', $schema, $this->document));
    }

    public function test_type_mismatch_message(): void
    {
        $error = $this->validator->validate(1, ['type' => 'string'], $this->document);

        $this->assertNotNull($error);
        $this->assertStringContainsString('string', $error);
        $this->assertStringContainsString('integer', $error);
    }

    public function test_invalid_pattern_returns_validation_error_without_php_warning(): void
    {
        $schema = ['type' => 'string', 'pattern' => '['];

        $this->assertSame(
            'pattern mismatch',
            $this->validator->validate('example', $schema, $this->document),
        );
    }
}
