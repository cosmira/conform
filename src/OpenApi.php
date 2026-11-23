<?php

declare(strict_types=1);

namespace Cosmira\OpenApi;

use Cosmira\OpenApi\Document\Document;
use Cosmira\OpenApi\Reader\Reader;
use Cosmira\OpenApi\Validation\RequestValidator;
use Cosmira\OpenApi\Validation\ResponseValidator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class OpenApi
{
    protected string $basePath = '';

    public function __construct(
        protected Document $document,
        protected RequestValidator $requests = new RequestValidator,
        protected ResponseValidator $responses = new ResponseValidator,
    ) {}

    public static function from(string $path): static
    {
        return is_dir($path)
            ? new static(Reader::directory($path))
            : new static(Reader::file($path));
    }

    public static function parse(string $contents): static
    {
        return new static(Reader::string($contents));
    }

    public function basePath(string $path): static
    {
        $api = clone $this;
        $api->basePath = trim($path, '/');

        return $api;
    }

    public function document(): Document
    {
        return $this->document;
    }

    /**
     * @return array{path: string, pathItem: array, operation: array, parameters: array<string, string>}|null
     */
    public function match(string $method, string $path): ?array
    {
        $method = strtolower($method);
        $path = $this->normalize($path);
        $best = null;
        $bestScore = PHP_INT_MAX;

        foreach ($this->document->paths() as $spec => $item) {
            if (! is_array($item) || ! isset($item[$method])) {
                continue;
            }

            $names = [];
            $pattern = preg_replace_callback('/\{([^}]+)\}/', function (array $m) use (&$names) {
                $names[] = $m[1];

                return '([^/]+)';
            }, $spec);

            if (! preg_match('#^' . $pattern . '$#', $path, $matches)) {
                continue;
            }

            $score = count($names);

            if ($score >= $bestScore) {
                continue;
            }

            $parameters = [];

            foreach ($names as $i => $name) {
                $parameters[$name] = $matches[$i + 1];
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

    /** @return list<string> */
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

    /** @return list<string> */
    public function response(Response $response, Request $request): array
    {
        $match = $this->match($request->getMethod(), $request->getPathInfo());

        if (! $match) {
            return [];
        }

        return $this->responses->validate($response, $match['operation'], $this->document);
    }

    protected function normalize(string $path): string
    {
        $path = '/' . ltrim($path, '/');

        if ($this->basePath === '') {
            return $path;
        }

        $prefix = '/' . $this->basePath;

        if ($path === $prefix) {
            return '/';
        }

        if (str_starts_with($path, $prefix . '/')) {
            return substr($path, strlen($prefix)) ?: '/';
        }

        return $path;
    }
}
