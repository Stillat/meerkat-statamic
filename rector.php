<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Catch_\ThrowWithPreviousExceptionRector;
use Rector\Config\RectorConfig;
use RectorLaravel\Set\LaravelLevelSetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withCache(__DIR__.'/build/rector')
    ->withPhpSets(php82: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
    )
    ->withSets([
        LaravelLevelSetList::UP_TO_LARAVEL_120,
    ])
    ->withSkip([
        ThrowWithPreviousExceptionRector::class => [
            __DIR__.'/src/Services/AkismetClient.php',
        ],
    ]);
