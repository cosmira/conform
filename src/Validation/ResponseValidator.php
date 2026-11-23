<?php

declare(strict_types=1);

namespace Cosmira\Conform\Validation;

use Cosmira\Conform\Document\Document;
use Symfony\Component\HttpFoundation\Response;

class ResponseValidator
{
    public function __construct(
        protected SchemaValidator $schemas = new SchemaValidator,
    ) {}

    /** @return list<string> */
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

        if (isset($spec['$ref'])) {
            $spec = $document->resolve($spec['$ref']) ?? $spec;
        }

        $errors = [];

        foreach ($spec['headers'] ?? [] as $name => $header) {
            if (! is_array($header)) {
                continue;
            }

            if (isset($header['$ref'])) {
                $header = $document->resolve($header['$ref']) ?? $header;
            }

            $value = $response->headers->get($name);

            if (($value === null || $value === '') && ($header['required'] ?? false)) {
                $errors[] = "Missing required response header [{$name}].";

                continue;
            }

            if ($value === null || ! isset($header['schema'])) {
                continue;
            }

            $schema = $header['schema'];

            if (isset($schema['$ref'])) {
                $schema = $document->resolve($schema['$ref']) ?? $schema;
            }

            if ($message = $this->schemas->validate($value, $schema, $document)) {
                $errors[] = "header.{$name}: {$message}";
            }
        }

        $content = $spec['content'] ?? [];

        if ($content === []) {
            return $errors;
        }

        $type = strtolower(trim(explode(';', (string) $response->headers->get('Content-Type', 'application/json'))[0]));
        $media = $content[$type] ?? $content['application/json'] ?? reset($content);

        if (! is_array($media) || ! isset($media['schema'])) {
            return $errors;
        }

        $payload = $response->getContent();
        $data = json_decode($payload === false || $payload === '' ? 'null' : $payload, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $errors[] = 'Response body is not valid JSON.';

            return $errors;
        }

        if ($message = $this->schemas->validate($data, $media['schema'], $document)) {
            $errors[] = "response: {$message}";
        }

        return $errors;
    }
}
