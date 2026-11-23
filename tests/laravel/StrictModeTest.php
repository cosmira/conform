<?php

declare(strict_types=1);

namespace Cosmira\Conform\Tests\Laravel;

use Cosmira\Conform\Laravel\ContractViolation;
use Cosmira\Conform\Laravel\Middleware\ValidateOpenApi;
use Cosmira\Conform\OpenApi;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use RuntimeException;

final class StrictModeTest extends TestCase
{
    public function createApplication(): Application
    {
        $bootstrap = __DIR__.'/build/bootstrap';

        if (! is_dir($bootstrap.'/cache')) {
            mkdir($bootstrap.'/cache', recursive: true);
        }

        $app = Application::configure(__DIR__)
            ->withRouting(using: static function (): void {
                Route::middleware(ValidateOpenApi::class)->group(static function (): void {
                    Route::get('/api/pets/{petId}', static fn (string $petId) => response()->json([
                        'id' => (int) $petId,
                        'name' => 'Rex',
                    ]));
                    Route::get('/api/pets/{petId}/tags', static fn () => response()->json([123]));
                    Route::post('/api/pets/{petId}', static fn () => response()->noContent());
                    Route::get('/api/undocumented', static fn () => response()->noContent());
                });
            })
            ->withExceptions()
            ->create();

        $app->useBootstrapPath($bootstrap);
        $app->afterBootstrapping(LoadConfiguration::class, static function (Application $app): void {
            $app['config']->set([
                'app.debug' => true,
                'conform.enabled' => true,
                'conform.path' => dirname(__DIR__).'/fixtures/petstore.yaml',
            ]);
        });
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    public function test_strict_mode_is_disabled_by_default(): void
    {
        $this->assertFalse(config('conform.strict'));

        $this->getJson('/api/undocumented')->assertNoContent();
    }

    public function test_default_mode_reports_invalid_responses_and_returns_them(): void
    {
        Exceptions::fake();

        $this->getJson('/api/pets/42/tags')->assertOk()->assertExactJson([123]);

        Exceptions::assertReported(
            static fn (RuntimeException $error): bool => str_contains(
                $error->getMessage(),
                'OpenAPI response mismatch:',
            ),
        );
    }

    public function test_strict_mode_accepts_a_documented_exchange(): void
    {
        config(['conform.strict' => true]);

        $this->getJson('/api/pets/42')->assertOk()->assertExactJson(['id' => 42, 'name' => 'Rex']);
    }

    public function test_strict_mode_throws_for_an_undocumented_route(): void
    {
        config(['conform.strict' => true]);
        $this->withoutExceptionHandling();

        $this->expectException(ContractViolation::class);
        $this->expectExceptionMessage('No OpenAPI operation for GET /api/undocumented.');

        $this->getJson('/api/undocumented');
    }

    public function test_strict_mode_requires_the_http_method_to_be_documented(): void
    {
        config(['conform.strict' => true]);
        $this->withoutExceptionHandling();

        $this->expectException(ContractViolation::class);
        $this->expectExceptionMessage('No OpenAPI operation for POST /api/pets/42.');

        $this->postJson('/api/pets/42');
    }

    public function test_strict_mode_throws_for_an_invalid_response(): void
    {
        config(['conform.strict' => true]);
        $this->withoutExceptionHandling();

        $this->expectException(ContractViolation::class);
        $this->expectExceptionMessage('OpenAPI response mismatch: response: [0]: expected string, got integer');

        $this->getJson('/api/pets/42/tags');
    }

    public function test_strict_mode_keeps_the_validation_response_for_an_invalid_request(): void
    {
        config(['conform.strict' => true]);

        $this->getJson('/api/pets/0')->assertUnprocessable()->assertJsonStructure(['message', 'errors']);
    }

    public function test_disabled_middleware_skips_strict_checks_and_leaves_the_validator_unresolved(): void
    {
        config([
            'conform.enabled' => false,
            'conform.strict' => true,
            'conform.path' => __DIR__.'/missing.yaml',
        ]);

        $this->getJson('/api/undocumented')->assertNoContent();
        $this->assertFalse($this->app->resolved(OpenApi::class));
    }
}
