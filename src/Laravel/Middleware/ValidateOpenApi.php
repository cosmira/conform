<?php

declare(strict_types=1);

namespace Cosmira\Conform\Laravel\Middleware;

use Closure;
use Cosmira\Conform\Laravel\ContractViolation;
use Cosmira\Conform\OpenApi;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateOpenApi
{
    /**
     * Validate the exchange when enabled and enforce the configured strictness.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('conform.enabled')) {
            return $next($request);
        }

        $route = $request->route();

        if ($route !== null) {
            $request->attributes->set('_route_params', $route->parameters());
        }

        $openapi = app(OpenApi::class);
        $match = config('conform.strict') ? $this->requireMatch($openapi, $request) : null;
        $errors = $match === null
            ? $openapi->request($request)
            : $openapi->requestForMatch($request, $match);

        if ($errors !== []) {
            return response()->json([
                'message' => 'Request does not match OpenAPI specification.',
                'errors' => $errors,
            ], 422);
        }

        $response = $next($request);

        $errors = $match === null
            ? $openapi->response($response, $request)
            : $openapi->responseForMatch($response, $match);

        if ($errors !== []) {
            $this->responseMismatch($errors, $match);
        }

        return $response;
    }

    /**
     * Require a documented method and path before running the route action.
     */
    private function requireMatch(OpenApi $openapi, Request $request): array
    {
        $method = $request->getMethod();
        $path = $request->getPathInfo();

        return $openapi->match($method, $path)
            ?? throw new ContractViolation("No OpenAPI operation for {$method} {$path}.");
    }

    /**
     * Turn a strict response mismatch into an exception or report it in normal mode.
     */
    private function responseMismatch(array $errors, ?array $match): void
    {
        $message = 'OpenAPI response mismatch: '.implode(' | ', $errors);

        if ($match !== null) {
            throw new ContractViolation($message);
        }

        report(new \RuntimeException($message));
    }
}
