<?php

declare(strict_types=1);

namespace Cosmira\Conform\Validation;

use Cosmira\Conform\Document\Document;
use Symfony\Component\HttpFoundation\Response;

class ResponseValidator
{
    /**
     * Default media type for response bodies.
     */
    private const JSON = 'application/json';

    /**
     * Use the supplied schema validator to check response values.
     */
    public function __construct(
        /**
         * Shared checker for response headers and bodies.
         */
        protected SchemaValidator $schemas = new SchemaValidator,
    ) {}

    /**
     * Select a response by status and collect header and body errors.
     *
     * @return list<string>
     */
    public function validate(Response $response, array $operation, Document $document): array
    {
        $status = (string) $response->getStatusCode();
        $responses = $operation['responses'] ?? [];

        $spec = $responses[$status]
            ?? $responses[substr($status, 0, 1).'XX']
            ?? $responses['default']
            ?? null;

        if ($spec === null) {
            return ["No response schema for status {$status}."];
        }

        $resolved = $this->resolve($spec, $document);

        return array_merge(
            $this->headers($response, $resolved['headers'] ?? [], $document),
            $this->body($response, $resolved['content'] ?? [], $document),
        );
    }

    /**
     * Check the headers declared by the selected response.
     */
    private function headers(Response $response, array $headers, Document $document): array
    {
        $errors = [];

        foreach ($headers as $name => $header) {
            if (! is_array($header)) {
                continue;
            }

            $resolved = $this->resolve($header, $document);
            $message = $this->headerError($response, $name, $resolved, $document);

            if ($message !== null) {
                $errors[] = $message;
            }
        }

        return $errors;
    }

    /**
     * Check required header presence and validate the value when present.
     */
    private function headerError(
        Response $response,
        string $name,
        array $header,
        Document $document,
    ): ?string {
        $value = $response->headers->get($name);

        if (($value === null || $value === '') && ($header['required'] ?? false)) {
            return "Missing required response header [{$name}].";
        }

        if ($value === null || ! isset($header['schema'])) {
            return null;
        }

        $schema = $this->resolve($header['schema'], $document);
        $message = $this->schemas->validate($value, $schema, $document);

        return $message ? "header.{$name}: {$message}" : null;
    }

    /**
     * Decode the response as JSON and check its selected media schema.
     */
    private function body(Response $response, array $content, Document $document): array
    {
        if ($content === []) {
            return [];
        }

        $contentType = (string) $response->headers->get('Content-Type', self::JSON);
        [$declaredType] = explode(';', $contentType);
        $type = strtolower(trim($declaredType));
        $media = $content[$type] ?? $content[self::JSON] ?? reset($content);

        if (! is_array($media) || ! isset($media['schema'])) {
            return [];
        }

        $payload = $response->getContent();
        $data = json_decode($payload === false || $payload === '' ? 'null' : $payload, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['Response body is not valid JSON.'];
        }

        $message = $this->schemas->validate($data, $media['schema'], $document);

        return $message ? ["response: {$message}"] : [];
    }

    /**
     * Use the referenced response fragment when the document resolves it.
     */
    private function resolve(array $fragment, Document $document): array
    {
        if (! isset($fragment['$ref'])) {
            return $fragment;
        }

        return $document->resolve($fragment['$ref']) ?? $fragment;
    }
}
