<?php

declare(strict_types=1);

use VimaTech\LaravelQuotas\Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Uses TestCase which extends Orchestra\Testbench\TestCase.
| This provides loadMigrationsFrom(), artisan(), etc. in Pest closures.
|
*/

uses(TestCase::class)->in('Feature', 'Unit');
