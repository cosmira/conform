# Conform

Conform validates HTTP requests and responses against OpenAPI 3.0 documents
in PHP and Laravel.

## Installation

```bash
composer require cosmira/conform
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

## Laravel

```bash
php artisan vendor:publish --tag=conform-config
```

```php
$middleware->alias([
    'conform' => \Cosmira\Conform\Laravel\Middleware\ValidateOpenApi::class,
]);

Route::middleware('conform')->group(function () {
    //
});
```

Conform enables validation when `APP_DEBUG=true` by default. Invalid requests
return HTTP `422`. Invalid responses are reported through Laravel.

```php
// config/conform.php
return [
    'path' => resource_path('openapi'),
    'enabled' => (bool) env('APP_DEBUG', false),
];
```

Set `enabled` to `false` to disable validation even in debug mode.
Set the optional `base_path` key if your API uses a prefix other than `api`.

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

## License

MIT
