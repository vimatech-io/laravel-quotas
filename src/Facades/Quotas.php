<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Facades;

use Illuminate\Support\Facades\Facade;
use VimaTech\LaravelQuotas\Managers\EntitlementManager;

/**
 * @method static \Illuminate\Support\Collection<int, \VimaTech\LaravelQuotas\Models\Plan> plans()
 * @method static \VimaTech\LaravelQuotas\Models\Plan plan(string $slug)
 * @method static \VimaTech\LaravelQuotas\Models\Plan|null currentPlan(\Illuminate\Database\Eloquent\Model $billable)
 * @method static \VimaTech\LaravelQuotas\Models\Subscription subscribe(\Illuminate\Database\Eloquent\Model $billable, string $planSlug, \VimaTech\LaravelQuotas\Enums\BillingInterval $interval = \VimaTech\LaravelQuotas\Enums\BillingInterval::Monthly)
 * @method static \VimaTech\LaravelQuotas\Models\Subscription cancel(\Illuminate\Database\Eloquent\Model $billable, bool $immediately = false)
 * @method static \VimaTech\LaravelQuotas\Models\Subscription swap(\Illuminate\Database\Eloquent\Model $billable, string $newPlanSlug)
 * @method static \VimaTech\LaravelQuotas\Models\Subscription resume(\Illuminate\Database\Eloquent\Model $billable)
 * @method static bool canUse(\Illuminate\Database\Eloquent\Model $billable, string $feature)
 * @method static void increment(\Illuminate\Database\Eloquent\Model $billable, string $feature, int $amount = 1)
 * @method static int|null remaining(\Illuminate\Database\Eloquent\Model $billable, string $feature)
 * @method static \VimaTech\LaravelQuotas\Managers\PlanManager planManager()
 * @method static \VimaTech\LaravelQuotas\Managers\QuotaManager quotaManager()
 * @method static \VimaTech\LaravelQuotas\Managers\SubscriptionManager subscriptionManager()
 *
 * @see EntitlementManager
 */
final class Quotas extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return EntitlementManager::class;
    }
}
