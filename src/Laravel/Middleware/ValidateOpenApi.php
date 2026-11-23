<?php

declare(strict_types=1);

namespace Cosmira\Conform\Laravel\Middleware;

use Closure;
use Cosmira\Conform\OpenApi;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateOpenApi
{
    /**
     * Validate the exchange when enabled and report any response mismatches.
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
        $errors = $openapi->request($request);

        if ($errors !== []) {
            return response()->json([
                'message' => 'Request does not match OpenAPI specification.',
                'errors' => $errors,
            ], 422);
        }

        $response = $next($request);

        $errors = $openapi->response($response, $request);

        if ($errors !== []) {
            report(new \RuntimeException(
                'OpenAPI response mismatch: '.implode(' | ', $errors)
            ));
        }

        return $response;
    }
}
