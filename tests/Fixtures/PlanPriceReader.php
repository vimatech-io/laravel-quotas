<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Tests\Fixtures;

use VimaTech\LaravelQuotas\Models\Plan;

/**
 * Reads prices the way a consumer would. PHPStan analyses this file, so a
 * price declared non-nullable fails `composer analyse` on these null checks.
 */
final class PlanPriceReader
{
    public function hasMonthlyPrice(Plan $plan): bool
    {
        return $plan->monthly_price !== null;
    }

    public function hasYearlyPrice(Plan $plan): bool
    {
        return $plan->yearly_price !== null;
    }
}
