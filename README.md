# Cosmira OpenAPI

Validate HTTP requests and responses against OpenAPI 3.0 documents.

## Installation

```bash
composer require cosmira/openapi
```

## Usage

```php
use Cosmira\OpenApi\OpenApi;

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
php artisan vendor:publish --tag=openapi-config
```

```php
$middleware->alias([
    'openapi' => \Cosmira\OpenApi\Laravel\Middleware\ValidateOpenApi::class,
]);

Route::middleware('openapi')->group(function () {
    //
});
```

Runs only when `APP_DEBUG=true`. Invalid request → `422`. Invalid response → reported.

```php
// config/openapi.php
return [
    'path' => resource_path('openapi'),
    'base_path' => 'api',
];
```

## What is validated

| Area | Support |
|------|---------|
| Multi-file `$ref` | `common.yaml#/…`, `../common.yaml#/…` |
| Path params | From URI template |
| Query / header / cookie params | Yes |
| JSON body | Yes |
| `multipart/form-data` / `x-www-form-urlencoded` | Yes (form fields + file names) |
| Response status | `200` → `2XX` → `default` |
| JSON response body | Yes |
| Response headers | Yes (when required / schema present) |
| Security `apiKey` | header / query / cookie presence |
| Security `http` bearer/basic | Authorization presence |
| Schema | `$ref`, types, formats (uuid/guid, date-time-micro, unixtime, binary, email, …), enum, bounds, required, properties, additionalProperties, items, nullable, allOf/oneOf/anyOf/not, discriminator |

## Read

Any OpenAPI object is available as data:

```php
$api->document()->get('info.contact.email');
$api->document()->get('components.securitySchemes.authToken');
```

## Not implemented

Callbacks execution, Link runtime expressions, OAuth token flows, XML wire format, example assertions.

## Testing

```bash
composer install
composer test
```

Coverage (needs `pcov` or `xdebug`):

```bash
composer test:coverage
```

Mutation testing ([Infection](https://infection.github.io/guide/), MSI ≥ 95%, all CPU threads):

```bash
composer mutate
# equivalent:
vendor/bin/infection --threads=max --min-msi=95 --min-covered-msi=95
```

## License

MIT
