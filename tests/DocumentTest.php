<?php

declare(strict_types=1);

namespace Cosmira\OpenApi\Tests;

use Cosmira\OpenApi\Reader\Reader;
use PHPUnit\Framework\TestCase;

class DocumentTest extends TestCase
{
    public function test_accessors_and_resolve(): void
    {
        $document = Reader::file(__DIR__ . '/fixtures/full.yaml');

        $this->assertSame('3.0.3', $document->version());
        $this->assertArrayHasKey('/pets', $document->paths());
        $this->assertSame('Full OpenAPI Coverage', $document->get('info.title'));
        $this->assertSame('API Team', $document->get('info.contact.name'));
        $this->assertSame('MIT', $document->get('info.license.name'));

        $error = $document->resolve('#/components/schemas/Error');
        $this->assertSame('object', $error['type']);
        $this->assertNull($document->resolve('#/components/schemas/Missing'));
        $this->assertNull($document->resolve('https://example.com/x'));
    }

    public function test_escaped_json_pointer(): void
    {
        $document = Reader::string(<<<'YAML'
openapi: 3.0.3
info:
  title: X
  version: 1
paths: {}
components:
  schemas:
    Foo/Bar:
      type: string
YAML);

        $this->assertSame(['type' => 'string'], $document->resolve('#/components/schemas/Foo~1Bar'));
    }
}
