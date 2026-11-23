<?php

declare(strict_types=1);

namespace Cosmira\OpenApi\Validation;

use Cosmira\OpenApi\Document\Document;

class SchemaValidator
{
    protected Document $document;

    protected ?string $from = null;

    public function validate(mixed $data, array $schema, Document $document, ?string $from = null): ?string
    {
        $this->document = $document;
        $this->from = $from;

        return $this->check($data, $this->resolve($schema), '');
    }

    protected function resolve(array $schema, int $depth = 0): array
    {
        if ($depth > 20 || ! isset($schema['$ref']) || ! is_string($schema['$ref'])) {
            return $schema;
        }

        $ref = $schema['$ref'];
        $resolved = $this->document->resolve($ref, $this->from);

        if ($resolved === null) {
            return $schema;
        }

        $this->from = $this->document->fileFor($ref, $this->from);
        unset($schema['$ref']);

        return $this->resolve(array_replace($resolved, $schema), $depth + 1);
    }

    protected function check(mixed $data, array $schema, string $path): ?string
    {
        $schema = $this->resolve($schema);

        if (($schema['nullable'] ?? false) === true && $data === null) {
            return null;
        }

        if (isset($schema['const']) && $data !== $schema['const']) {
            return $this->error($path, 'must equal ' . json_encode($schema['const']));
        }

        if (isset($schema['enum']) && ! in_array($data, $schema['enum'], true)) {
            return $this->error($path, 'must be one of ' . json_encode($schema['enum']));
        }

        if (isset($schema['discriminator']) && is_array($data)) {
            if ($error = $this->discriminator($data, $schema, $path)) {
                return $error;
            }
        }

        if (isset($schema['allOf'])) {
            foreach ($schema['allOf'] as $sub) {
                if ($error = $this->check($data, $sub, $path)) {
                    return $error;
                }
            }
        }

        if (isset($schema['anyOf'])) {
            $matched = false;

            foreach ($schema['anyOf'] as $sub) {
                if ($this->check($data, $sub, $path) === null) {
                    $matched = true;
                    break;
                }
            }

            if (! $matched) {
                return $this->error($path, 'must match at least one schema');
            }
        }

        if (isset($schema['oneOf'])) {
            $matches = 0;

            foreach ($schema['oneOf'] as $sub) {
                if ($this->check($data, $sub, $path) === null) {
                    $matches++;
                }
            }

            if ($matches !== 1) {
                return $this->error($path, 'must match exactly one schema');
            }
        }

        if (isset($schema['not']) && $this->check($data, $schema['not'], $path) === null) {
            return $this->error($path, 'must not match schema');
        }

        if (! isset($schema['type'])) {
            return $this->structure($data, $schema, $path);
        }

        $types = (array) $schema['type'];

        if (($schema['nullable'] ?? false) === true) {
            $types[] = 'null';
        }

        if (! $this->typeMatches($data, $types)) {
            return $this->error($path, 'expected ' . implode('|', $types) . ', got ' . $this->typeName($data));
        }

        return match (true) {
            in_array('object', $types, true) && is_array($data) && $this->isAssoc($data)
                => $this->object($data, $schema, $path),
            in_array('array', $types, true) && is_array($data) && ! $this->isAssoc($data)
                => $this->array($data, $schema, $path),
            in_array('string', $types, true) && is_string($data)
                => $this->string($data, $schema, $path),
            in_array('integer', $types, true) && is_int($data)
                => $this->number($data, $schema, $path),
            in_array('number', $types, true) && (is_int($data) || is_float($data))
                => $this->number($data, $schema, $path),
            default => null,
        };
    }

    protected function discriminator(array $data, array $schema, string $path): ?string
    {
        $property = $schema['discriminator']['propertyName'] ?? null;

        if (! is_string($property) || $property === '') {
            return null;
        }

        if (! array_key_exists($property, $data)) {
            return $this->error($path, "missing discriminator [{$property}]");
        }

        $value = $data[$property];
        $mapping = $schema['discriminator']['mapping'] ?? [];
        $ref = $mapping[$value] ?? (is_string($value) ? '#/components/schemas/' . $value : null);

        if (! is_string($ref)) {
            return null;
        }

        if (! str_contains($ref, '#') && ! str_starts_with($ref, '#')) {
            $ref = '#/components/schemas/' . $ref;
        }

        $target = $this->document->resolve($ref, $this->from);

        if ($target === null) {
            return $this->error($path, "unknown discriminator [{$value}]");
        }

        $this->from = $this->document->fileFor($ref, $this->from);

        return $this->check($data, $target, $path);
    }

    protected function structure(mixed $data, array $schema, string $path): ?string
    {
        if (isset($schema['properties']) || isset($schema['required']) || array_key_exists('additionalProperties', $schema)) {
            return is_array($data) && $this->isAssoc($data)
                ? $this->object($data, $schema, $path)
                : $this->error($path, 'expected object');
        }

        if (isset($schema['items'])) {
            return is_array($data) && ! $this->isAssoc($data)
                ? $this->array($data, $schema, $path)
                : $this->error($path, 'expected array');
        }

        return null;
    }

    protected function object(array $data, array $schema, string $path): ?string
    {
        if (isset($schema['minProperties']) && count($data) < $schema['minProperties']) {
            return $this->error($path, "minProperties {$schema['minProperties']}");
        }

        if (isset($schema['maxProperties']) && count($data) > $schema['maxProperties']) {
            return $this->error($path, "maxProperties {$schema['maxProperties']}");
        }

        foreach ($schema['required'] ?? [] as $key) {
            if (! array_key_exists($key, $data)) {
                return $this->error($path, "missing required property [{$key}]");
            }
        }

        $properties = $schema['properties'] ?? [];

        foreach ($data as $key => $value) {
            if (isset($properties[$key])) {
                if ($error = $this->check($value, $properties[$key], $this->join($path, (string) $key))) {
                    return $error;
                }
                continue;
            }

            $additional = $schema['additionalProperties'] ?? true;

            if ($additional === false) {
                return $this->error($path, "unexpected property [{$key}]");
            }

            if (is_array($additional) && $error = $this->check($value, $additional, $this->join($path, (string) $key))) {
                return $error;
            }
        }

        return null;
    }

    protected function array(array $data, array $schema, string $path): ?string
    {
        if (isset($schema['minItems']) && count($data) < $schema['minItems']) {
            return $this->error($path, "minItems {$schema['minItems']}");
        }

        if (isset($schema['maxItems']) && count($data) > $schema['maxItems']) {
            return $this->error($path, "maxItems {$schema['maxItems']}");
        }

        if (($schema['uniqueItems'] ?? false) === true) {
            $encoded = array_map(fn ($item) => json_encode($item), $data);
            if (count($encoded) !== count(array_unique($encoded))) {
                return $this->error($path, 'uniqueItems');
            }
        }

        if (! isset($schema['items'])) {
            return null;
        }

        foreach ($data as $i => $item) {
            if ($error = $this->check($item, $schema['items'], $this->join($path, "[{$i}]"))) {
                return $error;
            }
        }

        return null;
    }

    protected function string(string $data, array $schema, string $path): ?string
    {
        if (isset($schema['minLength']) && mb_strlen($data) < $schema['minLength']) {
            return $this->error($path, "minLength {$schema['minLength']}");
        }

        if (isset($schema['maxLength']) && mb_strlen($data) > $schema['maxLength']) {
            return $this->error($path, "maxLength {$schema['maxLength']}");
        }

        if (isset($schema['pattern']) && @preg_match('/' . $schema['pattern'] . '/u', $data) !== 1) {
            return $this->error($path, 'pattern mismatch');
        }

        return isset($schema['format']) ? $this->format($data, $schema['format'], $path) : null;
    }

    protected function number(int|float $data, array $schema, string $path): ?string
    {
        if (isset($schema['minimum']) && $data < $schema['minimum']) {
            return $this->error($path, "minimum {$schema['minimum']}");
        }

        if (isset($schema['maximum']) && $data > $schema['maximum']) {
            return $this->error($path, "maximum {$schema['maximum']}");
        }

        if (($schema['exclusiveMinimum'] ?? null) === true && isset($schema['minimum']) && $data <= $schema['minimum']) {
            return $this->error($path, "exclusiveMinimum {$schema['minimum']}");
        }

        if (is_numeric($schema['exclusiveMinimum'] ?? null) && $data <= $schema['exclusiveMinimum']) {
            return $this->error($path, "exclusiveMinimum {$schema['exclusiveMinimum']}");
        }

        if (($schema['exclusiveMaximum'] ?? null) === true && isset($schema['maximum']) && $data >= $schema['maximum']) {
            return $this->error($path, "exclusiveMaximum {$schema['maximum']}");
        }

        if (is_numeric($schema['exclusiveMaximum'] ?? null) && $data >= $schema['exclusiveMaximum']) {
            return $this->error($path, "exclusiveMaximum {$schema['exclusiveMaximum']}");
        }

        return null;
    }

    protected function format(string $data, string $format, string $path): ?string
    {
        $ok = match ($format) {
            'email' => filter_var($data, FILTER_VALIDATE_EMAIL) !== false,
            'uri', 'url' => filter_var($data, FILTER_VALIDATE_URL) !== false,
            'uuid', 'guid' => (bool) preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
                $data
            ),
            'date' => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $data),
            'date-time' => (bool) preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}/', $data),
            'date-time-micro', 'date-time-microtime' => (bool) preg_match(
                '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(\.\d+)?/',
                $data
            ),
            'unixtime' => (bool) preg_match('/^\d+$/', $data),
            // binary, color, json, checksum, timezone — accept any string
            'binary', 'byte', 'password', 'color', 'json', 'checksum', 'timezone' => true,
            default => true,
        };

        return $ok ? null : $this->error($path, "invalid {$format}");
    }

    protected function typeMatches(mixed $data, array $types): bool
    {
        foreach ($types as $type) {
            $ok = match ($type) {
                'null' => $data === null,
                'boolean' => is_bool($data),
                'object' => is_array($data) && $this->isAssoc($data),
                'array' => is_array($data) && ! $this->isAssoc($data),
                'string' => is_string($data),
                'integer' => is_int($data),
                'number' => is_int($data) || is_float($data),
                default => true,
            };

            if ($ok) {
                return true;
            }
        }

        return false;
    }

    protected function isAssoc(array $data): bool
    {
        return $data === [] || array_keys($data) !== range(0, count($data) - 1);
    }

    protected function typeName(mixed $data): string
    {
        return match (true) {
            $data === null => 'null',
            is_bool($data) => 'boolean',
            is_int($data) => 'integer',
            is_float($data) => 'number',
            is_string($data) => 'string',
            is_array($data) => $this->isAssoc($data) ? 'object' : 'array',
            default => gettype($data),
        };
    }

    protected function error(string $path, string $message): string
    {
        return $path === '' ? $message : "{$path}: {$message}";
    }

    protected function join(string $path, string $segment): string
    {
        if ($segment === '' || str_starts_with($segment, '[')) {
            return $path.$segment;
        }

        return $path === '' ? $segment : "{$path}.{$segment}";
    }
}
