<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Contracts;

use VimaTech\LaravelQuotas\Enums\BillingInterval;
use VimaTech\LaravelQuotas\Exceptions\LocalSubscriptionsDisabledException;
use VimaTech\LaravelQuotas\Models\Subscription;

/**
 * The subscription lifecycle of a billable model, for type-hinting.
 *
 * Kept apart from QuotaAware because the two have different guarantees.
 * Entitlements resolve the same way whoever owns the subscription; these
 * methods write to this package's own table and throw
 * LocalSubscriptionsDisabledException under a Cashier resolver, where creating
 * and cancelling subscriptions is Cashier's job.
 *
 * Satisfied by the HasQuotas trait. Type-hint it when your code genuinely
 * manages local subscriptions — a portal, an admin screen — and check
 * quotas.subscriptions.resolver before calling.
 */
interface ManagesLocalSubscription
{
    /**
     * @throws LocalSubscriptionsDisabledException
     */
    public function subscribe(string $planSlug, BillingInterval $interval = BillingInterval::Monthly): Subscription;

    /**
     * @throws LocalSubscriptionsDisabledException
     */
    public function swapPlan(string $newPlanSlug): Subscription;

    /**
     * @throws LocalSubscriptionsDisabledException
     */
    public function cancelSubscription(bool $immediately = false): Subscription;

    /**
     * @throws LocalSubscriptionsDisabledException
     */
    public function resumeSubscription(): Subscription;

    public function currentSubscription(): ?Subscription;
}
