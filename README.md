# Conform

Conform checks HTTP requests and responses against OpenAPI 3.0 contracts in
PHP and Laravel tests.

## Installation

```bash
composer require --dev cosmira/conform
```

## Compatibility

| Dependency | Supported versions |
| --- | --- |
| PHP | 8.2 and later in the 8.x series |
| Illuminate Support | 10, 11, 12, 13 |
| Symfony Finder, HttpFoundation, and YAML | 6, 7, 8 |

Composer selects versions compatible with your PHP version.
[GitHub Actions](.github/workflows/phpunit.yml) tests PHP 8.2 through 8.6 on Linux
and PHP 8.5 on Windows. It also tests the lowest resolvable dependencies on
PHP 8.2. The PHP 8.6 job requires Illuminate 13, Symfony 8, PHPUnit 13, and
Infection 0.35.

## Usage

```php
use Cosmira\Conform\OpenApi;

$api = OpenApi::from('openapi.yaml'); // or a directory of YAML/JSON files

$errors = $api->request($request);
$errors = $api->response($response, $request);
```

Empty array means valid.

```php
$api = OpenApi::from(resource_path('openapi'))->basePath('xapi');

$api->match('GET', '/xapi/users/1');
$api->document()->resolve('common.yaml#/components/parameters/limit');
```

```php
OpenApi::parse($yaml);
```

## Laravel feature tests

Add Conform to the `api` middleware group in the base class for your API
feature tests. The routes under test must use that group.

```php
use Cosmira\Conform\Laravel\Middleware\ValidateOpenApi;
use Tests\TestCase;

abstract class ApiTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['conform.enabled' => true, 'conform.strict' => true]);
        $this->app['router']->pushMiddlewareToGroup('api', ValidateOpenApi::class);
        $this->withoutExceptionHandling();
    }
}
```

Strict mode throws `Cosmira\Conform\Laravel\ContractViolation` when the HTTP
method and path have no OpenAPI operation or the response fails validation.
`withoutExceptionHandling()` lets the exception reach PHPUnit directly.

Put your specification in `resources/openapi`. Set `conform.path` to use a
different file or directory. Set `conform.base_path` if your API uses a prefix
other than `api`.

To edit the defaults in `config/conform.php`, publish the configuration.

```bash
php artisan vendor:publish --tag=conform-config
```

The default configuration follows `APP_DEBUG` and leaves strict mode off.
In that mode, unmatched requests pass through and Laravel receives a report
for invalid responses. Invalid requests return HTTP `422` in either mode.
Test coverage depends on which operations the suite exercises and which
constraints the OpenAPI document declares.

## What is validated

| Area | Support |
| --- | --- |
| Multi-file `$ref` | `common.yaml#/…`, `../common.yaml#/…` |
| Path params | From URI template |
| Query / header / cookie params | Yes |
| JSON body | Yes |
| `multipart/form-data`, `x-www-form-urlencoded` | Fields and file names |
| Response status | `200` → `2XX` → `default` |
| JSON response body | Yes |
| Response headers | Yes (when required / schema present) |
| Security `apiKey` | header / query / cookie presence |
| Security `http` bearer/basic | Authorization presence |
| Schema references and composition | `$ref`, `allOf`, `oneOf`, `anyOf`, `not` |
| Schema values | Types, formats, enums, bounds, `nullable` |
| Schema structure | `required`, `properties`, `additionalProperties`, `items` |
| Polymorphism | `discriminator` |

Supported formats include UUID, GUID, `date-time-micro`, `unixtime`, `binary`,
and `email`.

## Read

Any OpenAPI object is available as data:

```php
$api->document()->get('info.contact.email');
$api->document()->get('components.securitySchemes.authToken');
```

## Not implemented

Callback execution, Link runtime expressions, OAuth token flows, XML wire format,
and example assertions.

## Testing

```bash
composer install
composer test
```

Run the Laravel feature suite in its own Composer project.

```bash
composer --working-dir=tests/laravel install
composer --working-dir=tests/laravel test
```

Run Rector in check mode with `composer test:rector`. The rules target PHP 8.2
and preserve compatibility with PHPUnit 11.

For coverage and mutation testing, enable PCOV or Xdebug.

```bash
composer test:coverage
```

Run [Infection](https://infection.github.io/guide/) with Composer.

```bash
composer mutate
```

Infection runs PHPUnit before generating mutations. `composer mutate` requires
95% for both the overall and covered mutation scores.
[The CI mutation job](.github/workflows/mutation.yml) enforces a 60% baseline on
both scores and uploads the report. See `infection-summary.log` for local results.

## Benchmark

```bash
composer benchmark
```

The benchmark reads the Petstore YAML fixture, matches a route among 103 paths,
and validates a JSON request, a 100-item response, and a full validation
exchange. The validation scenarios reuse the parsed document and HTTP objects.
The JSON report shows the median and range of nine samples in microseconds
per operation. Compare reports on the same machine with the same PHP settings
and dependency versions. CI uploads a `conform-benchmark` report for each run.

The suite scenarios cycle through 25 endpoints among 100, 1,000, and 5,000
paths. Each operation measures Conform's request and response checks.
Laravel, application code, and the database run outside this benchmark.

## License

MIT
