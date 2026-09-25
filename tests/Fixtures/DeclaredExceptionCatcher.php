<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use VimaTech\LaravelQuotas\Actions\IncrementUsageAction;
use VimaTech\LaravelQuotas\Exceptions\BillableNotCashierReadyException;
use VimaTech\LaravelQuotas\Exceptions\PlanNotFoundException;

/**
 * Catches the way a consumer would. PHPStan analyses this file, so a @throws
 * missing from the called method fails `composer analyse` as a dead catch.
 */
final class DeclaredExceptionCatcher
{
    public function __construct(
        private readonly IncrementUsageAction $increment,
    ) {}

    public function increment(Model $billable, string $feature): ?PlanNotFoundException
    {
        try {
            $this->increment->execute($billable, $feature);
        } catch (PlanNotFoundException $e) {
            return $e;
        }

        return null;
    }

    public function incrementUsage(CashierUser $billable, string $feature): ?PlanNotFoundException
    {
        try {
            $billable->incrementUsage($feature);
        } catch (PlanNotFoundException $e) {
            return $e;
        }

        return null;
    }

    public function canUse(User $billable, string $feature): ?BillableNotCashierReadyException
    {
        try {
            $billable->canUse($feature);
        } catch (BillableNotCashierReadyException $e) {
            return $e;
        }

        return null;
    }
}
