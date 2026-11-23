<?php

declare(strict_types=1);

namespace Cosmira\Conform\Tests;

use Cosmira\Conform\Reader\Reader;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ReaderTest extends TestCase
{
    public function test_file_yaml_and_json(): void
    {
        $this->assertSame('3.0.3', Reader::file(__DIR__.'/fixtures/users.yaml')->version());
        $this->assertSame('listUsers', Reader::file(__DIR__.'/fixtures/users.json')->get('paths./users.get.operationId'));
    }

    public function test_string_autodetects_format(): void
    {
        $yaml = Reader::string("openapi: 3.0.3\ninfo:\n  title: A\n  version: 1\npaths:\n  /ping:\n    get:\n      responses:\n        '200':\n          description: ok");
        $this->assertArrayHasKey('/ping', $yaml->paths());

        $json = Reader::string('{"openapi":"3.0.3","info":{"title":"A","version":"1"},"paths":{"/ping":{"get":{"responses":{"200":{"description":"ok"}}}}}}');
        $this->assertArrayHasKey('/ping', $json->paths());
    }

    public function test_directory_merges(): void
    {
        $document = Reader::directory(__DIR__.'/fixtures');

        $this->assertArrayHasKey('/pets', $document->paths());
        $this->assertArrayHasKey('/users', $document->paths());
    }

    public function test_missing_file(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Reader::file(__DIR__.'/fixtures/nope.yaml');
    }

    public function test_invalid_document(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Reader::string("info:\n  title: X\n  version: 1");
    }

    public function test_empty_directory(): void
    {
        $dir = sys_get_temp_dir().'/openapi-empty-'.uniqid();
        mkdir($dir);

        try {
            $this->expectException(RuntimeException::class);
            Reader::directory($dir);
        } finally {
            rmdir($dir);
        }
    }

    public function test_unsupported_extension(): void
    {
        $tmp = sys_get_temp_dir().'/openapi-'.uniqid().'.xml';
        file_put_contents($tmp, '<x/>');

        try {
            $this->expectException(InvalidArgumentException::class);
            Reader::file($tmp);
        } finally {
            @unlink($tmp);
        }
    }
}
