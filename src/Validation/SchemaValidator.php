<?php

declare(strict_types=1);

namespace Cosmira\Conform\Validation;

use Cosmira\Conform\Document\Document;

class SchemaValidator
{
    /**
     * OpenAPI type name for a null value.
     */
    private const NULL = 'null';

    /**
     * OpenAPI type name for a JSON object.
     */
    private const OBJECT = 'object';

    /**
     * OpenAPI type name for a JSON array.
     */
    private const ARRAY = 'array';

    /**
     * OpenAPI type name for a string.
     */
    private const STRING = 'string';

    /**
     * OpenAPI type name for an integer.
     */
    private const INTEGER = 'integer';

    /**
     * OpenAPI type name for an integer or floating-point number.
     */
    private const NUMBER = 'number';

    /**
     * Document that provides the component and external reference targets.
     */
    protected Document $document;

    /**
     * Current reference origin within a multi-file specification.
     */
    protected ?string $from = null;

    /**
     * Check a value against a schema and return its first error, if any.
     */
    public function validate(
        mixed $data,
        array $schema,
        Document $document,
        ?string $from = null,
    ): ?string {
        $this->document = $document;
        $this->from = $from;

        return $this->check($data, $this->resolve($schema), '');
    }

    /**
     * Follow schema references and retain the origin for nested references.
     */
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

    /**
     * Run literal, composition, and type checks in error priority order.
     */
    protected function check(mixed $data, array $schema, string $path): ?string
    {
        $rules = $this->resolve($schema);

        if (($rules['nullable'] ?? false) === true && $data === null) {
            return null;
        }

        return $this->literalError($data, $rules, $path)
            ?? $this->compositionError($data, $rules, $path)
            ?? $this->typedError($data, $rules, $path);
    }

    /**
     * Report mismatches against constant and enum values.
     */
    private function literalError(mixed $data, array $schema, string $path): ?string
    {
        if (isset($schema['const']) && $data !== $schema['const']) {
            return $this->error($path, 'must equal '.json_encode($schema['const']));
        }

        if (isset($schema['enum']) && ! in_array($data, $schema['enum'], true)) {
            return $this->error($path, 'must be one of '.json_encode($schema['enum']));
        }

        return null;
    }

    /**
     * Apply discriminator and composition constraints before type checks.
     */
    private function compositionError(mixed $data, array $schema, string $path): ?string
    {
        if (isset($schema['discriminator']) && is_array($data)) {
            $error = $this->discriminator($data, $schema, $path);

            if ($error !== null) {
                return $error;
            }
        }

        return $this->allOfError($data, $schema['allOf'] ?? [], $path)
            ?? $this->alternativesError($data, $schema, $path);
    }

    /**
     * Return the first error from a schema required by allOf.
     */
    private function allOfError(mixed $data, array $schemas, string $path): ?string
    {
        foreach ($schemas as $schema) {
            $error = $this->check($data, $schema, $path);

            if ($error !== null) {
                return $error;
            }
        }

        return null;
    }

    /**
     * Enforce the anyOf, oneOf, and not constraints.
     */
    private function alternativesError(mixed $data, array $schema, string $path): ?string
    {
        if (isset($schema['anyOf']) && ! $this->matchesAny($data, $schema['anyOf'], $path)) {
            return $this->error($path, 'must match at least one schema');
        }

        if (isset($schema['oneOf']) && $this->matchingCount($data, $schema['oneOf'], $path) !== 1) {
            return $this->error($path, 'must match exactly one schema');
        }

        if (isset($schema['not']) && $this->check($data, $schema['not'], $path) === null) {
            return $this->error($path, 'must not match schema');
        }

        return null;
    }

    /**
     * Stop at the first valid schema alternative.
     */
    private function matchesAny(mixed $data, array $schemas, string $path): bool
    {
        foreach ($schemas as $schema) {
            if ($this->check($data, $schema, $path) === null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Count every valid schema alternative for oneOf.
     */
    private function matchingCount(mixed $data, array $schemas, string $path): int
    {
        $matches = 0;

        foreach ($schemas as $schema) {
            if ($this->check($data, $schema, $path) === null) {
                $matches++;
            }
        }

        return $matches;
    }

    /**
     * Check inferred structure or validate the explicit type constraints.
     */
    private function typedError(mixed $data, array $schema, string $path): ?string
    {
        if (! isset($schema['type'])) {
            return $this->structure($data, $schema, $path);
        }

        $types = (array) $schema['type'];

        if (($schema['nullable'] ?? false) === true) {
            $types[] = self::NULL;
        }

        if (! $this->typeMatches($data, $types)) {
            $actual = $this->typeName($data);

            return $this->error($path, 'expected '.implode('|', $types).', got '.$actual);
        }

        return $this->checkValue($data, $types, $schema, $path);
    }

    /**
     * Apply type-specific constraints after confirming that a type matches.
     */
    private function checkValue(mixed $data, array $types, array $schema, string $path): ?string
    {
        $actual = $this->typeName($data);
        $expected = $actual === self::INTEGER && in_array(self::NUMBER, $types, true)
            ? self::NUMBER
            : $actual;

        if (! in_array($expected, $types, true) || is_object($data)) {
            return null;
        }

        $checks = [
            self::OBJECT => $this->object(...),
            self::ARRAY => $this->array(...),
            self::STRING => $this->string(...),
            self::INTEGER => $this->number(...),
            self::NUMBER => $this->number(...),
        ];

        $check = $checks[$expected] ?? null;

        return $check === null ? null : $check($data, $schema, $path);
    }

    /**
     * Follow the schema selected by the discriminator property.
     */
    protected function discriminator(array $data, array $schema, string $path): ?string
    {
        $discriminator = $schema['discriminator'];
        $property = $discriminator['propertyName'] ?? null;

        if (! is_string($property) || $property === '') {
            return null;
        }

        if (! array_key_exists($property, $data)) {
            return $this->error($path, "missing discriminator [{$property}]");
        }

        $value = $data[$property];
        $mapping = $discriminator['mapping'] ?? [];
        $ref = $mapping[$value] ?? (is_string($value) ? '#/components/schemas/'.$value : null);

        if (! is_string($ref)) {
            return null;
        }

        if (! str_contains($ref, '#')) {
            $ref = '#/components/schemas/'.$ref;
        }

        $target = $this->document->resolve($ref, $this->from);

        if ($target === null) {
            return $this->error($path, "unknown discriminator [{$value}]");
        }

        $this->from = $this->document->fileFor($ref, $this->from);

        return $this->check($data, $target, $path);
    }

    /**
     * Infer object or array constraints when a schema omits its type.
     */
    protected function structure(mixed $data, array $schema, string $path): ?string
    {
        if ($this->hasObjectRules($schema)) {
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

    /**
     * Detect keywords that imply an object in an untyped schema.
     */
    private function hasObjectRules(array $schema): bool
    {
        return isset($schema['properties']) || isset($schema['required'])
            || array_key_exists('additionalProperties', $schema);
    }

    /**
     * Check object size, required keys, and property schemas.
     */
    protected function object(array $data, array $schema, string $path): ?string
    {
        return $this->boundsError(count($data), $schema, 'minProperties', 'maxProperties', $path)
            ?? $this->requiredPropertyError($data, $schema['required'] ?? [], $path)
            ?? $this->objectValuesError($data, $schema, $path);
    }

    /**
     * Return the first declared property missing from the object.
     */
    private function requiredPropertyError(array $data, array $required, string $path): ?string
    {
        foreach ($required as $key) {
            if (! array_key_exists($key, $data)) {
                return $this->error($path, "missing required property [{$key}]");
            }
        }

        return null;
    }

    /**
     * Check defined properties and apply the additional-property policy.
     */
    private function objectValuesError(array $data, array $schema, string $path): ?string
    {
        $properties = $schema['properties'] ?? [];
        $additional = $schema['additionalProperties'] ?? true;

        foreach ($data as $key => $value) {
            $defined = isset($properties[$key]);
            $rules = $properties[$key] ?? $additional;

            if (! $defined && $rules === false) {
                return $this->error($path, "unexpected property [{$key}]");
            }

            if (! $defined && ! is_array($rules)) {
                continue;
            }

            $error = $this->check($value, $rules, $this->join($path, (string) $key));

            if ($error !== null) {
                return $error;
            }
        }

        return null;
    }

    /**
     * Check array length, uniqueness, and item schemas.
     */
    protected function array(array $data, array $schema, string $path): ?string
    {
        return $this->boundsError(count($data), $schema, 'minItems', 'maxItems', $path)
            ?? $this->duplicateItemError($data, $schema, $path)
            ?? $this->itemError($data, $schema, $path);
    }

    /**
     * Detect duplicate JSON values when the schema requires unique items.
     */
    private function duplicateItemError(array $data, array $schema, string $path): ?string
    {
        if (($schema['uniqueItems'] ?? false) !== true) {
            return null;
        }

        $encoded = array_map(json_encode(...), $data);

        return count($encoded) === count(array_unique($encoded))
            ? null
            : $this->error($path, 'uniqueItems');
    }

    /**
     * Return the first item error with its array index.
     */
    private function itemError(array $data, array $schema, string $path): ?string
    {
        if (! isset($schema['items'])) {
            return null;
        }

        foreach ($data as $index => $item) {
            $error = $this->check($item, $schema['items'], $this->join($path, "[{$index}]"));

            if ($error !== null) {
                return $error;
            }
        }

        return null;
    }

    /**
     * Check string length, pattern, and format constraints.
     */
    protected function string(string $data, array $schema, string $path): ?string
    {
        $length = mb_strlen($data);
        $lengthError = $this->boundsError($length, $schema, 'minLength', 'maxLength', $path);

        if ($lengthError !== null) {
            return $lengthError;
        }

        if (isset($schema['pattern']) && ! Pattern::matches((string) $schema['pattern'], $data)) {
            return $this->error($path, 'pattern mismatch');
        }

        return isset($schema['format']) ? $this->format($data, $schema['format'], $path) : null;
    }

    /**
     * Check inclusive and exclusive numeric bounds in order.
     */
    protected function number(int|float $data, array $schema, string $path): ?string
    {
        return $this->boundsError($data, $schema, 'minimum', 'maximum', $path)
            ?? $this->exclusiveBoundsError($data, $schema, $path);
    }

    /**
     * Check minimum and maximum values using the supplied schema keywords.
     */
    private function boundsError(
        int|float $value,
        array $schema,
        string $minimum,
        string $maximum,
        string $path,
    ): ?string {
        if (isset($schema[$minimum]) && $value < $schema[$minimum]) {
            return $this->error($path, "{$minimum} {$schema[$minimum]}");
        }

        if (isset($schema[$maximum]) && $value > $schema[$maximum]) {
            return $this->error($path, "{$maximum} {$schema[$maximum]}");
        }

        return null;
    }

    /**
     * Check strict numeric bounds in both supported representations.
     */
    private function exclusiveBoundsError(int|float $data, array $schema, string $path): ?string
    {
        $minimum = $this->exclusiveBound(
            $schema['exclusiveMinimum'] ?? null,
            $schema['minimum'] ?? null,
        );
        $maximum = $this->exclusiveBound(
            $schema['exclusiveMaximum'] ?? null,
            $schema['maximum'] ?? null,
        );

        if ($minimum !== null && $data <= $minimum) {
            return $this->error($path, "exclusiveMinimum {$minimum}");
        }

        if ($maximum !== null && $data >= $maximum) {
            return $this->error($path, "exclusiveMaximum {$maximum}");
        }

        return null;
    }

    /**
     * Read a numeric exclusive bound or reuse its inclusive keyword value.
     */
    private function exclusiveBound(mixed $exclusive, mixed $inclusive): mixed
    {
        if ($exclusive === true) {
            return $inclusive;
        }

        return is_numeric($exclusive) ? $exclusive : null;
    }

    /**
     * Check a known string format and accept unrecognized format annotations.
     */
    protected function format(string $data, string $format, string $path): ?string
    {
        $filters = [
            'email' => FILTER_VALIDATE_EMAIL,
            'uri' => FILTER_VALIDATE_URL,
            'url' => FILTER_VALIDATE_URL,
        ];

        $valid = isset($filters[$format])
            ? filter_var($data, $filters[$format]) !== false
            : $this->matchesFormatPattern($data, $format);

        return $valid ? null : $this->error($path, "invalid {$format}");
    }

    /**
     * Check formats described by a fixed regular expression.
     */
    private function matchesFormatPattern(string $data, string $format): bool
    {
        $uuid = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
        $microtime = '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(\.\d+)?/';
        $patterns = [
            'uuid' => $uuid,
            'guid' => $uuid,
            'date' => '/^\d{4}-\d{2}-\d{2}$/',
            'date-time' => '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}/',
            'date-time-micro' => $microtime,
            'date-time-microtime' => $microtime,
            'unixtime' => '/^\d+$/',
        ];

        return ! isset($patterns[$format]) || preg_match($patterns[$format], $data) === 1;
    }

    /**
     * Test whether the value satisfies at least one declared type.
     */
    protected function typeMatches(mixed $data, array $types): bool
    {
        foreach ($types as $type) {
            if ($this->matchesType($data, $type)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Match one OpenAPI type against its PHP representation.
     */
    private function matchesType(mixed $data, mixed $type): bool
    {
        $checks = [
            self::NULL => is_null(...),
            'boolean' => is_bool(...),
            self::OBJECT => fn (mixed $value): bool => is_array($value) && $this->isAssoc($value),
            self::ARRAY => fn (mixed $value): bool => is_array($value) && ! $this->isAssoc($value),
            self::STRING => is_string(...),
            self::INTEGER => is_int(...),
            self::NUMBER => static fn (mixed $value): bool => is_int($value) || is_float($value),
        ];

        $check = is_string($type) ? ($checks[$type] ?? null) : null;

        return $check === null || $check($data);
    }

    /**
     * Recognize JSON objects decoded as arrays, including empty objects.
     */
    protected function isAssoc(array $data): bool
    {
        return $data === [] || array_keys($data) !== range(0, count($data) - 1);
    }

    /**
     * Describe the PHP value using the names used in validation errors.
     */
    protected function typeName(mixed $data): string
    {
        if (is_array($data)) {
            return $this->isAssoc($data) ? self::OBJECT : self::ARRAY;
        }

        return match (gettype($data)) {
            'NULL' => self::NULL,
            'double' => self::NUMBER,
            default => gettype($data),
        };
    }

    /**
     * Prefix the validation message with its location when available.
     */
    protected function error(string $path, string $message): string
    {
        return $path === '' ? $message : "{$path}: {$message}";
    }

    /**
     * Append an object property or array index to a validation path.
     */
    protected function join(string $path, string $segment): string
    {
        if ($segment === '' || str_starts_with($segment, '[')) {
            return $path.$segment;
        }

        return $path === '' ? $segment : "{$path}.{$segment}";
    }
}
