<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\PHPUnit\PHPUnit120\Rector\Class_\AllowMockObjectsForDataProviderRector;

return RectorConfig::configure()
    ->withPHPStanConfigs([__DIR__.'/phpstan.neon'])
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withPreparedSets(
        deadCode: true,
        privatization: true,
        instanceOf: true,
        earlyReturn: true,
    )
    ->withComposerBased(phpunit: true)
    ->withSkip([
        // PHPUnit 11 does not provide this attribute.
        AllowMockObjectsForDataProviderRector::class,
    ])
    ->withPhpSets(php82: true);
