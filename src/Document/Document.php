<?php

declare(strict_types=1);

namespace Cosmira\Conform\Document;

use Illuminate\Support\Arr;

class Document
{
    /**
     * Index a merged specification and its source documents for reference resolution.
     *
     * @param  array<string, array>  $files  relative path → parsed document
     */
    public function __construct(
        /**
         * Merged document used for operation and component lookup.
         */
        protected array $data,
        /**
         * Parsed documents keyed by their paths relative to the bundle root.
         */
        protected array $files = [],
    ) {
        if ($this->files === []) {
            $this->files = ['' => $this->data];
        }
    }

    /**
     * Read the OpenAPI version declared by the specification.
     */
    public function version(): string
    {
        return (string) ($this->data['openapi'] ?? '');
    }

    /**
     * Return the path items indexed by URI template.
     */
    public function paths(): array
    {
        return $this->data['paths'] ?? [];
    }

    /**
     * Return the parsed specification as an array.
     */
    public function raw(): array
    {
        return $this->data;
    }

    /**
     * Return the original documents indexed by bundle-relative path.
     *
     * @return array<string, array>
     */
    public function files(): array
    {
        return $this->files;
    }

    /**
     * Read a value by its dotted key, falling back when the key is absent.
     */
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
     * Split a reference into its source file and fragment pointer.
     *
     * @return array{0: string, 1: string} [file, pointer without leading #/]
     */
    protected function parse(string $ref, ?string $from): array
    {
        $ref = trim($ref, " \t\"'");
        $origin = $from ?? '';

        if (! str_contains($ref, '#')) {
            return [$origin, ltrim($ref, '#/')];
        }

        [$file, $pointer] = explode('#', $ref, 2);
        $file = str_replace('\\', '/', trim($file));

        if ($file !== '') {
            $file = $this->join($origin, $file);
        } else {
            $file = $origin;
        }

        return [$file, ltrim($pointer, '/')];
    }

    /**
     * Find the source document, with merged data available for root references.
     */
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

    /**
     * Decode JSON Pointer escapes and build a key for the array lookup.
     */
    protected function pointerPath(string $pointer): string
    {
        return collect(explode('/', $pointer))
            ->map(fn (string $segment) => str_replace(['~1', '~0'], ['/', '~'], $segment))
            ->implode('.');
    }

    /**
     * Resolve a relative reference against the directory of its source file.
     */
    protected function join(string $from, string $relative): string
    {
        $relative = str_replace('\\', '/', $relative);

        if ($from === '' || $from === '.') {
            return ltrim($relative, './');
        }

        $parts = $this->parentSegments($from);

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

    /**
     * Find the directory segments of the file containing the reference.
     */
    protected function parentSegments(string $file): array
    {
        $directory = dirname($file);

        return $directory === '.' ? [] : explode('/', $directory);
    }
}
