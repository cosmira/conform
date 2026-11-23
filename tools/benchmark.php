<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Cosmira\Conform\Document\Document;
use Cosmira\Conform\OpenApi;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

require dirname(__DIR__).'/vendor/autoload.php';

$fixture = dirname(__DIR__).'/tests/fixtures/petstore.yaml';
$api = OpenApi::from($fixture);
$spec = $api->document()->raw();
$paths = [];

for ($index = 1; $index <= 100; $index++) {
    $paths["/groups/{groupId}/resources/{$index}"] = [
        'get' => ['operationId' => "getResource{$index}"],
    ];
}

$spec['paths'] = $paths + $spec['paths'];
$routingApi = new OpenApi(new Document($spec));

$headers = ['Content-Type' => 'application/json'];
$create = Request::create('/pets', 'POST', server: ['CONTENT_TYPE' => 'application/json'],
    content: '{"name":"Rex","status":"available","meta":{"source":"benchmark"}}');
$created = new Response('{"id":1,"name":"Rex","status":"available"}', 201, $headers);
$list = Request::create('/pets?limit=100');
$pets = [];

for ($id = 1; $id <= 100; $id++) {
    $pets[] = ['id' => $id, 'name' => 'Pet '.$id, 'status' => 'available', 'meta' => ['type' => 'dog']];
}

$listJson = json_encode($pets, JSON_THROW_ON_ERROR);
$listed = new Response($listJson, 200, $headers);
$invalidRequest = Request::create('/pets', 'POST', server: ['CONTENT_TYPE' => 'application/json'],
    content: '{"status":"available"}');
$invalidResponse = new Response('{"name":"Rex"}', 201, $headers);
unset($pets[99]['id']);
$invalidList = new Response(json_encode($pets, JSON_THROW_ON_ERROR), 200, $headers);

// Check rejection paths before timing, so a validation no-op cannot pass as a fast run.
if (
    $api->request($invalidRequest) !== ['body: missing required property [name]']
    || $api->response($invalidResponse, $create) !== ['response: missing required property [id]']
    || $api->response($invalidList, $list) !== ['response: [99]: missing required property [id]']
) {
    throw new RuntimeException('The Petstore fixture did not reject the invalid benchmark inputs.');
}

$scenarios = [
    'load_petstore_yaml' => [50, static fn (): bool => OpenApi::from($fixture)->document()->version() === '3.0.3'],
    'match_among_103_paths' => [500, static function () use ($routingApi): bool {
        $match = $routingApi->match('GET', '/pets/42');

        return $match !== null
            && $match['operation']['operationId'] === 'showPet'
            && $match['parameters'] === ['petId' => '42'];
    }],
    'validate_json_request' => [500, static fn (): bool => $api->request($create) === []],
    'validate_json_response_100_items' => [25, static fn (): bool => $api->response($listed, $list) === []],
    'validate_json_exchange' => [500, static fn (): bool => $api->request($create) === []
        && $api->response($created, $create) === []],
];

$warmup = 20;
$sampleCount = 9;
$results = [];

foreach ($scenarios as $name => [$iterations, $run]) {
    for ($iteration = 0; $iteration < $warmup; $iteration++) {
        if (! $run()) {
            throw new RuntimeException("Unexpected result during warmup for {$name}.");
        }
    }

    $samples = [];

    for ($sample = 0; $sample < $sampleCount; $sample++) {
        $start = hrtime(true);

        for ($iteration = 0; $iteration < $iterations; $iteration++) {
            if (! $run()) {
                throw new RuntimeException("Unexpected result during measurement for {$name}.");
            }
        }

        $samples[] = round((hrtime(true) - $start) / $iterations / 1_000, 3);
    }

    $sorted = $samples;
    sort($sorted, SORT_NUMERIC);

    $results[$name] = [
        'operations' => $iterations * $sampleCount,
        'failures' => 0,
        'iterations_per_sample' => $iterations,
        'median_us' => $sorted[intdiv($sampleCount, 2)],
        'min_us' => $sorted[0],
        'max_us' => $sorted[$sampleCount - 1],
        'samples_us' => $samples,
    ];
}

echo json_encode([
    'php' => PHP_VERSION,
    'platform' => PHP_OS_FAMILY.'/'.php_uname('m'),
    'opcache_cli' => (bool) ini_get('opcache.enable_cli'),
    'opcache_jit' => ini_get('opcache.jit'),
    'dependencies' => [
        'illuminate/support' => InstalledVersions::getPrettyVersion('illuminate/support'),
        'symfony/http-foundation' => InstalledVersions::getPrettyVersion('symfony/http-foundation'),
        'symfony/yaml' => InstalledVersions::getPrettyVersion('symfony/yaml'),
    ],
    'workload' => [
        'specification_bytes' => filesize($fixture),
        'routing_paths' => count($spec['paths']),
        'list_response_items' => count($pets),
        'list_response_bytes' => strlen($listJson),
    ],
    'warmup_per_scenario' => $warmup,
    'sample_count' => $sampleCount,
    'scenarios' => $results,
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
