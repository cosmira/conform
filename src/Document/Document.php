<?php

declare(strict_types=1);

namespace Cosmira\OpenApi\Document;

use Illuminate\Support\Arr;

class Document
{
    /**
     * @param  array<string, array>  $files  relative path → parsed document
     */
    public function __construct(
        protected array $data,
        protected array $files = [],
    ) {
        if ($this->files === []) {
            $this->files = ['' => $this->data];
        }
    }

    public function version(): string
    {
        return (string) ($this->data['openapi'] ?? '');
    }

    public function paths(): array
    {
        return $this->data['paths'] ?? [];
    }

    public function raw(): array
    {
        return $this->data;
    }

    /** @return array<string, array> */
    public function files(): array
    {
        return $this->files;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->data, $key, $default);
    }

    /**
     * Resolve a $ref to an array fragment.
     *
     * #/components/schemas/User
     * common.yaml#/components/parameters/limit
     * ../common.yaml#/components/schemas/Error
     *
     * @param  string|null  $from  file that contained the $ref (relative path in the bundle)
     */
    public function resolve(string $ref, ?string $from = null): ?array
    {
        [$file, $pointer] = $this->parse($ref, $from);
        $source = $this->source($file, $ref, $from);

        if ($source === null) {
            return null;
        }

        if ($pointer === '') {
            return $source;
        }

        $value = Arr::get($source, $this->pointerPath($pointer));

        return is_array($value) ? $value : null;
    }

    /**
     * File that owns the target of $ref (for nested #/ resolution).
     */
    public function fileFor(string $ref, ?string $from = null): string
    {
        return $this->parse($ref, $from)[0];
    }

    /**
     * @return array{0: string, 1: string} [file, pointer without leading #/]
     */
    protected function parse(string $ref, ?string $from): array
    {
        $ref = trim($ref, " \t\"'");
        $from = $from ?? '';

        if (! str_contains($ref, '#')) {
            return [$from, ltrim($ref, '#/')];
        }

        [$file, $pointer] = explode('#', $ref, 2);
        $file = str_replace('\\', '/', trim($file));

        if ($file !== '') {
            $file = $this->join($from, $file);
        } else {
            $file = $from;
        }

        return [$file, ltrim($pointer, '/')];
    }

    protected function source(string $file, string $ref, ?string $from): ?array
    {
        if (isset($this->files[$file])) {
            return $this->files[$file];
        }

        // Local #/ refs on a merged document when no file context
        if (($from === null || $from === '') && str_starts_with(trim($ref, " \t\"'"), '#/')) {
            return $this->data;
        }

        return null;
    }

    protected function pointerPath(string $pointer): string
    {
        return collect(explode('/', $pointer))
            ->map(fn (string $segment) => str_replace(['~1', '~0'], ['/', '~'], $segment))
            ->implode('.');
    }

    protected function join(string $from, string $relative): string
    {
        $relative = str_replace('\\', '/', $relative);

        if ($from === '' || $from === '.') {
            return ltrim($relative, './');
        }

        $directory = str_contains($from, '/') ? dirname($from) : '';
        $parts = ($directory === '' || $directory === '.') ? [] : explode('/', $directory);

        foreach (explode('/', $relative) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                array_pop($parts);
                continue;
            }

            $parts[] = $part;
        }

        return implode('/', $parts);
    }
}
