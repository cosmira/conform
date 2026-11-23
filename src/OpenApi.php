<?php

declare(strict_types=1);

namespace Cosmira\Conform;

use Cosmira\Conform\Document\Document;
use Cosmira\Conform\Reader\Reader;
use Cosmira\Conform\Validation\RequestValidator;
use Cosmira\Conform\Validation\ResponseValidator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** @phpstan-consistent-constructor */
class OpenApi
{
    /**
     * Optional URI prefix removed before matching OpenAPI paths.
     */
    protected string $basePath = '';

    /**
     * Bind request and response validation to a parsed specification.
     */
    public function __construct(
        /**
         * OpenAPI specification used to match operations and resolve references.
         */
        protected Document $document,
        /**
         * Validator for request parameters, bodies, and security requirements.
         */
        protected RequestValidator $requests = new RequestValidator,
        /**
         * Validator for response statuses, headers, and bodies.
         */
        protected ResponseValidator $responses = new ResponseValidator,
    ) {}

    /**
     * Load a standalone OpenAPI file or a directory of related documents.
     */
    public static function from(string $path): static
    {
        return is_dir($path)
            ? new static(Reader::directory($path))
            : new static(Reader::file($path));
    }

    /**
     * Read an OpenAPI document from its YAML or JSON text.
     */
    public static function parse(string $contents): static
    {
        return new static(Reader::string($contents));
    }

    /**
     * Return a copy that recognizes the given API prefix.
     */
    public function basePath(string $path): static
    {
        $api = clone $this;
        $api->basePath = trim($path, '/');

        return $api;
    }

    /**
     * Expose the parsed document for direct reference and metadata lookup.
     */
    public function document(): Document
    {
        return $this->document;
    }

    /**
     * Find the most specific operation and extract the URI template parameters.
     *
     * @return array{
     *     path: string, pathItem: array, operation: array, parameters: array<string, string>
     * }|null
     */
    public function match(string $method, string $path): ?array
    {
        $method = strtolower($method);
        $requestPath = $this->normalize($path);
        $best = null;
        $bestScore = PHP_INT_MAX;

        foreach ($this->document->paths() as $spec => $item) {
            if (! is_array($item) || ! isset($item[$method])) {
                continue;
            }

            $names = [];
            $pattern = $this->pathPattern($spec, $names);

            if (! preg_match('#^'.$pattern.'$#', $requestPath, $matches)) {
                continue;
            }

            $score = count($names);

            if ($score >= $bestScore) {
                continue;
            }

            $parameters = [];

            foreach ($names as $index => $name) {
                $parameters[$name] = $matches[$index + 1];
            }

            $bestScore = $score;
            $best = [
                'path' => $spec,
                'pathItem' => $item,
                'operation' => $item[$method],
                'parameters' => $parameters,
            ];
        }

        return $best;
    }

    /**
     * Compile path placeholders in order and collect their parameter names.
     */
    private function pathPattern(string $template, array &$names): ?string
    {
        return preg_replace_callback(
            '/\{([^}]+)\}/',
            static function (array $capture) use (&$names) {
                $names[] = $capture[1];

                return '([^/]+)';
            },
            $template,
        );
    }

    /**
     * Validate the request for its matched operation and return the error messages.
     *
     * @return list<string>
     */
    public function request(Request $request): array
    {
        $match = $this->match($request->getMethod(), $request->getPathInfo());

        if (! $match) {
            return [];
        }

        return $this->requests->validate(
            $request,
            $match['operation'],
            $match['pathItem'],
            $this->document,
            $match['parameters'],
        );
    }

    /**
     * Validate a response against the operation matched by its request.
     *
     * @return list<string>
     */
    public function response(Response $response, Request $request): array
    {
        $match = $this->match($request->getMethod(), $request->getPathInfo());

        if (! $match) {
            return [];
        }

        return $this->responses->validate($response, $match['operation'], $this->document);
    }

    /**
     * Remove a configured API prefix at the path boundary.
     */
    protected function normalize(string $path): string
    {
        $absolutePath = '/'.ltrim($path, '/');

        if ($this->basePath === '') {
            return $absolutePath;
        }

        $prefix = '/'.$this->basePath;

        if ($absolutePath === $prefix) {
            return '/';
        }

        if (str_starts_with($absolutePath, $prefix.'/')) {
            return substr($absolutePath, strlen($prefix)) ?: '/';
        }

        return $absolutePath;
    }
}
