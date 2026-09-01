<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;

it('lets the published migrations be dated when the consumer publishes them', function () {
    $packageMigrations = realpath(__DIR__.'/../../database/migrations');

    $registered = array_map(
        fn (string $path): string => (string) realpath($path),
        ServiceProvider::publishableMigrationPaths()
    );

    expect($registered)->toContain($packageMigrations);
});
