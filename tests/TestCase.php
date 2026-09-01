<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use VimaTech\LaravelQuotas\Facades\Quotas;
use VimaTech\LaravelQuotas\LaravelQuotasServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            LaravelQuotasServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'Quotas' => Quotas::class,
        ];
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.migrations.update_date_on_publish', true);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

    }
}
