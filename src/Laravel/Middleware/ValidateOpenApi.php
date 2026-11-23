<?php

declare(strict_types=1);

namespace Cosmira\Conform\Laravel\Middleware;

use Closure;
use Cosmira\Conform\OpenApi;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateOpenApi
{
    public function __construct(
        protected OpenApi $openapi,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->hasDebugModeEnabled()) {
            return $next($request);
        }

        if ($route = $request->route()) {
            $request->attributes->set('_route_params', $route->parameters());
        }

        $errors = $this->openapi->request($request);

        if ($errors !== []) {
            return response()->json([
                'message' => 'Request does not match OpenAPI specification.',
                'errors' => $errors,
            ], 422);
        }

        $response = $next($request);

        $errors = $this->openapi->response($response, $request);

        if ($errors !== []) {
            report(new \RuntimeException(
                'OpenAPI response mismatch: '.implode(' | ', $errors)
            ));
        }

        return $response;
    }
}
