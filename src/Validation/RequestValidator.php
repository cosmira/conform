<?php

declare(strict_types=1);

namespace Cosmira\Conform\Validation;

use Cosmira\Conform\Document\Document;
use Symfony\Component\HttpFoundation\Request;

class RequestValidator
{
    public function __construct(
        protected SchemaValidator $schemas = new SchemaValidator,
    ) {}

    /**
     * @param  array<string, string>  $pathParameters
     * @return list<string>
     */
    public function validate(
        Request $request,
        array $operation,
        array $pathItem,
        Document $document,
        array $pathParameters = [],
    ): array {
        $errors = $this->security($request, $operation, $pathItem, $document);

        foreach ($this->parameters($operation, $pathItem, $document) as $parameter) {
            $name = $parameter['name'] ?? null;
            $in = $parameter['in'] ?? null;

            if (! is_string($name) || ! is_string($in)) {
                continue;
            }

            $value = $this->value($request, $in, $name, $pathParameters);
            $required = ($parameter['required'] ?? false) || $in === 'path';

            if ($value === null || $value === '') {
                if ($required) {
                    $errors[] = "Missing required {$in} parameter [{$name}].";
                }

                continue;
            }

            $schema = $parameter['schema'] ?? null;

            if (! is_array($schema)) {
                continue;
            }

            $schema = $this->expand($schema, $document);
            $value = $this->cast($value, $schema);

            if ($message = $this->schemas->validate($value, $schema, $document)) {
                $errors[] = "{$in}.{$name}: {$message}";
            }
        }

        if (isset($operation['requestBody'])) {
            $errors = array_merge($errors, $this->body($request, $operation['requestBody'], $document));
        }

        return $errors;
    }

    /** @return list<string> */
    protected function security(Request $request, array $operation, array $pathItem, Document $document): array
    {
        $requirements = $operation['security']
            ?? $pathItem['security']
            ?? $document->get('security')
            ?? null;

        // Explicit empty security: optional auth
        if ($requirements === []) {
            return [];
        }

        if (! is_array($requirements) || $requirements === null) {
            return [];
        }

        // OpenAPI: OR between requirement objects; AND within each object
        foreach ($requirements as $requirement) {
            if (! is_array($requirement)) {
                continue;
            }

            $missing = [];

            foreach ($requirement as $schemeName => $scopes) {
                if (! is_string($schemeName)) {
                    continue;
                }

                $scheme = $this->scheme($schemeName, $document);

                if ($scheme === null) {
                    continue;
                }

                if ($this->schemeValue($request, $scheme) === null) {
                    $label = $scheme['name'] ?? $schemeName;
                    $in = $scheme['in'] ?? 'header';
                    $missing[] = "{$in} [{$label}]";
                }
            }

            if ($missing === []) {
                return [];
            }
        }

        // None of the OR groups satisfied — report first group's gaps
        $first = null;

        foreach ($requirements as $requirement) {
            if (is_array($requirement)) {
                $first = $requirement;
                break;
            }
        }

        if ($first === null) {
            return [];
        }

        $errors = [];

        foreach ($first as $schemeName => $scopes) {
            if (! is_string($schemeName)) {
                continue;
            }

            $scheme = $this->scheme($schemeName, $document);

            if ($scheme === null) {
                continue;
            }

            if ($this->schemeValue($request, $scheme) === null) {
                $name = $scheme['name'] ?? $schemeName;
                $in = $scheme['in'] ?? 'header';
                $errors[] = "Missing required security {$in} [{$name}].";
            }
        }

        return $errors;
    }

    protected function scheme(string $name, Document $document): ?array
    {
        $scheme = $document->get("components.securitySchemes.{$name}");

        if (! is_array($scheme)) {
            // Try resolve from any file (multi-file may leave only $ref in merged map)
            foreach ($document->files() as $file => $data) {
                $candidate = $data['components']['securitySchemes'][$name] ?? null;

                if (! is_array($candidate)) {
                    continue;
                }

                $scheme = $candidate;
                break;
            }
        }

        if (! is_array($scheme)) {
            return null;
        }

        if (isset($scheme['$ref'])) {
            $scheme = $document->resolve($scheme['$ref']) ?? $scheme;
        }

        return is_array($scheme) ? $scheme : null;
    }

    protected function schemeValue(Request $request, array $scheme): mixed
    {
        $type = $scheme['type'] ?? null;

        if ($type === 'apiKey') {
            $name = $scheme['name'] ?? null;
            $in = $scheme['in'] ?? 'header';

            if (! is_string($name)) {
                return null;
            }

            return match ($in) {
                'header' => $request->headers->get($name),
                'query' => $request->query->get($name),
                'cookie' => $request->cookies->get($name),
                default => null,
            };
        }

        if ($type === 'http') {
            $auth = $request->headers->get('Authorization');

            if ($auth === null || $auth === '') {
                return null;
            }

            $httpScheme = strtolower((string) ($scheme['scheme'] ?? 'bearer'));

            if ($httpScheme === 'bearer') {
                return str_starts_with(strtolower($auth), 'bearer ') ? $auth : null;
            }

            if ($httpScheme === 'basic') {
                return str_starts_with(strtolower($auth), 'basic ') ? $auth : null;
            }

            return $auth;
        }

        // oauth2 / openIdConnect — presence of Authorization only
        if (in_array($type, ['oauth2', 'openIdConnect'], true)) {
            $auth = $request->headers->get('Authorization');

            return ($auth !== null && $auth !== '') ? $auth : null;
        }

        return null;
    }

    /** @return list<array> */
    protected function parameters(array $operation, array $pathItem, Document $document): array
    {
        $resolved = [];

        foreach (array_merge($pathItem['parameters'] ?? [], $operation['parameters'] ?? []) as $parameter) {
            if (! is_array($parameter)) {
                continue;
            }

            if (isset($parameter['$ref'])) {
                $parameter = $document->resolve($parameter['$ref']) ?? $parameter;
            }

            if (! isset($parameter['name'], $parameter['in'])) {
                continue;
            }

            $resolved[$parameter['in'].':'.$parameter['name']] = $parameter;
        }

        return array_values($resolved);
    }

    /** @param  array<string, string>  $pathParameters */
    protected function value(Request $request, string $in, string $name, array $pathParameters): mixed
    {
        return match ($in) {
            'path' => $pathParameters[$name]
                ?? $request->attributes->get('_route_params')[$name] ?? null
                ?? $request->attributes->get($name),
            'query' => $request->query->get($name),
            'header' => $request->headers->get($name),
            'cookie' => $request->cookies->get($name),
            default => null,
        };
    }

    protected function expand(array $schema, Document $document): array
    {
        for ($i = 0; $i < 10 && isset($schema['$ref']) && is_string($schema['$ref']); $i++) {
            $resolved = $document->resolve($schema['$ref']);

            if ($resolved === null) {
                break;
            }

            unset($schema['$ref']);
            $schema = array_replace($resolved, $schema);
        }

        return $schema;
    }

    protected function cast(mixed $value, array $schema): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return match ($schema['type'] ?? null) {
            'integer' => preg_match('/^-?\d+$/', $value) ? (int) $value : $value,
            'number' => is_numeric($value) ? $value + 0 : $value,
            'boolean' => match (strtolower($value)) {
                'true', '1' => true,
                'false', '0' => false,
                default => $value,
            },
            default => $value,
        };
    }

    /** @return list<string> */
    protected function body(Request $request, array $body, Document $document): array
    {
        if (isset($body['$ref'])) {
            $body = $document->resolve($body['$ref']) ?? $body;
        }

        $content = $body['content'] ?? [];

        if ($content === []) {
            return [];
        }

        $type = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type', 'application/json'))[0]));
        $media = $content[$type]
            ?? (str_starts_with($type, 'multipart/') ? ($content['multipart/form-data'] ?? null) : null)
            ?? $content['application/json']
            ?? reset($content);

        if (! is_array($media) || ! isset($media['schema'])) {
            return [];
        }

        $raw = $request->getContent();
        $payload = $this->decode($request, $type, is_string($raw) ? $raw : '');

        if (($body['required'] ?? false) && $payload === [] && ($raw === false || $raw === '') && $request->request->count() === 0 && $request->files->count() === 0) {
            return ['Request body is required.'];
        }

        if ($message = $this->schemas->validate($payload, $media['schema'], $document)) {
            return ["body: {$message}"];
        }

        return [];
    }

    protected function decode(Request $request, string $contentType, string $raw): array
    {
        if (str_starts_with($contentType, 'multipart/') || $contentType === 'application/x-www-form-urlencoded') {
            $data = $request->request->all();

            foreach ($request->files->all() as $key => $file) {
                if (is_object($file) && method_exists($file, 'getClientOriginalName')) {
                    $data[$key] = $file->getClientOriginalName();
                } elseif (is_array($file)) {
                    $data[$key] = array_map(function ($item) {
                        return is_object($item) && method_exists($item, 'getClientOriginalName')
                            ? $item->getClientOriginalName()
                            : 'file';
                    }, $file);
                } else {
                    $data[$key] = 'file';
                }
            }

            return $data;
        }

        if ($raw !== '') {
            $decoded = json_decode($raw, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return $request->request->all();
    }
}
