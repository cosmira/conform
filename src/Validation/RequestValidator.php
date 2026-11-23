<?php

declare(strict_types=1);

namespace Cosmira\Conform\Validation;

use Cosmira\Conform\Document\Document;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;

class RequestValidator
{
    /**
     * OpenAPI location for HTTP header parameters.
     */
    private const HEADER = 'header';

    /**
     * Default media type for request bodies.
     */
    private const JSON = 'application/json';

    /**
     * Use the supplied schema validator to check request values.
     */
    public function __construct(
        /**
         * Shared checker for parameter and body schemas.
         */
        protected SchemaValidator $schemas = new SchemaValidator,
    ) {}

    /**
     * Collect security, parameter, and body errors for the matched operation.
     *
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
            $message = $this->parameterError(
                $request,
                $parameter,
                $pathParameters,
                $document,
            );

            if ($message !== null) {
                $errors[] = $message;
            }
        }

        if (isset($operation['requestBody'])) {
            $bodyErrors = $this->body($request, $operation['requestBody'], $document);

            return array_merge($errors, $bodyErrors);
        }

        return $errors;
    }

    /**
     * Check one declared parameter and prefix any error with its location.
     */
    private function parameterError(
        Request $request,
        array $parameter,
        array $pathParameters,
        Document $document,
    ): ?string {
        $name = $parameter['name'] ?? null;
        $location = $parameter['in'] ?? null;

        if (! is_string($name) || ! is_string($location)) {
            return null;
        }

        $value = $this->value($request, $location, $name, $pathParameters);

        if ($value === null || $value === '') {
            return $this->missingParameter($parameter, $location, $name);
        }

        $schema = $parameter['schema'] ?? null;

        if (! is_array($schema)) {
            return null;
        }

        $schema = $this->expand($schema, $document);
        $message = $this->schemas->validate($this->cast($value, $schema), $schema, $document);

        return $message ? "{$location}.{$name}: {$message}" : null;
    }

    /**
     * Report an absent parameter when the operation requires it.
     */
    private function missingParameter(array $parameter, string $location, string $name): ?string
    {
        return ($parameter['required'] ?? false) || $location === 'path'
            ? "Missing required {$location} parameter [{$name}]."
            : null;
    }

    /**
     * Accept any complete security group or report the first group's errors.
     *
     * @return list<string>
     */
    protected function security(
        Request $request,
        array $operation,
        array $pathItem,
        Document $document,
    ): array {
        $requirements = $operation['security']
            ?? $pathItem['security']
            ?? $document->get('security');

        if (! is_array($requirements)) {
            return [];
        }

        $first = null;

        // An operation accepts any complete group of security schemes.
        foreach ($requirements as $requirement) {
            if (! is_array($requirement)) {
                continue;
            }

            $errors = $this->missingSchemes($request, $requirement, $document);

            if ($errors === []) {
                return [];
            }

            $first ??= $errors;
        }

        return $first ?? [];
    }

    /**
     * Collect credentials missing from one security requirement group.
     */
    private function missingSchemes(Request $request, array $requirement, Document $document): array
    {
        $errors = [];

        foreach (array_keys($requirement) as $schemeName) {
            if (! is_string($schemeName)) {
                continue;
            }

            $scheme = $this->scheme($schemeName, $document);

            if ($scheme === null || $this->schemeValue($request, $scheme) !== null) {
                continue;
            }

            $name = $scheme['name'] ?? $schemeName;
            $location = $scheme['in'] ?? self::HEADER;
            $errors[] = "Missing required security {$location} [{$name}].";
        }

        return $errors;
    }

    /**
     * Find a named security scheme and resolve its reference when present.
     */
    protected function scheme(string $name, Document $document): ?array
    {
        $scheme = $document->get("components.securitySchemes.{$name}");

        if (! is_array($scheme)) {
            // Try resolve from any file (multi-file may leave only $ref in merged map)
            foreach ($document->files() as $data) {
                $schemes = Arr::get($data, 'components.securitySchemes', []);
                $candidate = $schemes[$name] ?? null;

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

    /**
     * Read the credential required by the declared security scheme.
     */
    protected function schemeValue(Request $request, array $scheme): mixed
    {
        $type = $scheme['type'] ?? null;

        if ($type === 'apiKey') {
            return $this->apiKey($request, $scheme);
        }

        $credentials = $request->headers->get('Authorization');

        if ($credentials === null || $credentials === '') {
            return null;
        }

        return match ($type) {
            'http' => $this->httpCredentials($credentials, $scheme),
            'oauth2', 'openIdConnect' => $credentials,
            default => null,
        };
    }

    /**
     * Read an API key from its configured header, query, or cookie location.
     */
    private function apiKey(Request $request, array $scheme): mixed
    {
        $name = $scheme['name'] ?? null;

        if (! is_string($name)) {
            return null;
        }

        return $this->parameterBag($request, $scheme['in'] ?? self::HEADER)?->get($name);
    }

    /**
     * Require a matching prefix for Basic or Bearer credentials.
     */
    private function httpCredentials(string $credentials, array $scheme): ?string
    {
        $prefix = strtolower((string) ($scheme['scheme'] ?? 'bearer'));

        if (in_array($prefix, ['bearer', 'basic'], true)) {
            return str_starts_with(strtolower($credentials), $prefix.' ') ? $credentials : null;
        }

        return $credentials;
    }

    /**
     * Combine inherited and operation parameters, preferring operation definitions.
     *
     * @return list<array>
     */
    protected function parameters(array $operation, array $pathItem, Document $document): array
    {
        $resolved = [];

        $parameters = array_merge($pathItem['parameters'] ?? [], $operation['parameters'] ?? []);

        foreach ($parameters as $parameter) {
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

    /**
     * Read a request parameter, preferring matched template values for paths.
     *
     * @param  array<string, string>  $pathParameters
     */
    protected function value(
        Request $request,
        string $in,
        string $name,
        array $pathParameters,
    ): mixed {
        if ($in === 'path') {
            $routeParameters = $request->attributes->get('_route_params');

            return $pathParameters[$name]
                ?? $routeParameters[$name]
                ?? $request->attributes->get($name);
        }

        return $this->parameterBag($request, $in)?->get($name);
    }

    /**
     * Select the HTTP parameter bag for a header, query, or cookie.
     */
    private function parameterBag(Request $request, string $location): HeaderBag|ParameterBag|null
    {
        $bags = [
            self::HEADER => $request->headers,
            'query' => $request->query,
            'cookie' => $request->cookies,
        ];

        return $bags[$location] ?? null;
    }

    /**
     * Follow parameter schema references before coercing request values.
     */
    protected function expand(array $schema, Document $document): array
    {
        $expanded = $schema;

        for ($depth = 0; $depth < 10; $depth++) {
            $reference = $expanded['$ref'] ?? null;

            if (! is_string($reference)) {
                break;
            }

            $resolved = $document->resolve($reference);

            if ($resolved === null) {
                break;
            }

            unset($expanded['$ref']);
            $expanded = array_replace($resolved, $expanded);
        }

        return $expanded;
    }

    /**
     * Convert a string parameter when the schema expects a numeric or boolean value.
     */
    protected function cast(mixed $value, array $schema): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return match ($schema['type'] ?? null) {
            'integer' => preg_match('/^-?\d+$/', $value) ? (int) $value : $value,
            'number' => is_numeric($value) ? $value + 0 : $value,
            'boolean' => $this->castBoolean($value),
            default => $value,
        };
    }

    /**
     * Recognize common boolean strings and leave other strings for validation.
     */
    private function castBoolean(string $value): bool|string
    {
        $booleans = ['true' => true, '1' => true, 'false' => false, '0' => false];

        return $booleans[strtolower($value)] ?? $value;
    }

    /**
     * Select the request body schema, decode the input, and validate the payload.
     *
     * @return list<string>
     */
    protected function body(Request $request, array $body, Document $document): array
    {
        $reference = $body['$ref'] ?? null;
        $spec = $reference === null ? $body : ($document->resolve($reference) ?? $body);
        $content = $spec['content'] ?? [];

        [$declaredType] = explode(';', (string) $request->headers->get('Content-Type', self::JSON));
        $type = strtolower(trim($declaredType));
        $media = $this->bodyMedia($content, $type);

        if (! is_array($media) || ! isset($media['schema'])) {
            return [];
        }

        $raw = $request->getContent();
        $payload = $this->decode($request, $type, (string) $raw);

        if (($spec['required'] ?? false) && $payload === [] && $this->emptyBody($request, $raw)) {
            return ['Request body is required.'];
        }

        $message = $this->schemas->validate($payload, $media['schema'], $document);

        return $message ? ["body: {$message}"] : [];
    }

    /**
     * Pick the declared media type or the nearest supported fallback.
     */
    private function bodyMedia(array $content, string $type): mixed
    {
        $multipart = str_starts_with($type, 'multipart/')
            ? ($content['multipart/form-data'] ?? null)
            : null;

        return $content[$type] ?? $multipart ?? $content[self::JSON] ?? reset($content);
    }

    /**
     * Check whether the request has any raw content, fields, or file uploads.
     */
    private function emptyBody(Request $request, string|false $raw): bool
    {
        return ($raw === false || $raw === '')
            && $request->request->count() === 0 && $request->files->count() === 0;
    }

    /**
     * Read structured form or JSON data and fall back to parsed request fields.
     */
    protected function decode(Request $request, string $contentType, string $raw): array
    {
        if (
            str_starts_with($contentType, 'multipart/')
            || $contentType === 'application/x-www-form-urlencoded'
        ) {
            return $this->formData($request);
        }

        $decoded = $raw === '' ? null : json_decode($raw, true);

        return is_array($decoded) ? $decoded : $request->request->all();
    }

    /**
     * Combine form fields with the original names of uploaded files.
     */
    private function formData(Request $request): array
    {
        $data = $request->request->all();

        foreach ($request->files->all() as $key => $file) {
            $data[$key] = is_array($file)
                ? array_map($this->fileName(...), $file)
                : $this->fileName($file);
        }

        return $data;
    }

    /**
     * Read the original upload name when the file object provides it.
     */
    private function fileName(mixed $file): string
    {
        return is_object($file) && method_exists($file, 'getClientOriginalName')
            ? $file->getClientOriginalName()
            : 'file';
    }
}
