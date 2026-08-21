<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Actions;

use Illuminate\Support\Carbon;
use VimaTech\LaravelQuotas\Enums\BillingInterval;
use VimaTech\LaravelQuotas\Enums\SubscriptionStatus;
use VimaTech\LaravelQuotas\Events\SubscriptionCancelled;
use VimaTech\LaravelQuotas\Managers\QuotaManager;
use VimaTech\LaravelQuotas\Models\Subscription;
use VimaTech\LaravelQuotas\Support\LocalSubscriptionGuard;

final class CancelSubscriptionAction
{
    public function __construct(
        private readonly QuotaManager $quotaManager,
    ) {}

    public function execute(Subscription $subscription, bool $immediately = false): Subscription
    {
        LocalSubscriptionGuard::ensureEnabled(__METHOD__);

        $subscription->update([
            'status' => SubscriptionStatus::Cancelled,
            'cancelled_at' => now(),
            'ends_at' => $immediately ? now() : $this->periodEndFor($subscription),
        ]);

        $billable = $subscription->billable;

        if ($billable !== null) {
            $this->quotaManager->forgetPlan($billable);
        }

        event(new SubscriptionCancelled($subscription, $billable));

        return $subscription->fresh();
    }

    /**
     * When a cancelled-at-period-end subscription actually stops.
     *
     * The customer has already paid for the period in progress and keeps it.
     * That period is whatever the subscription was sold for: a yearly
     * subscriber cancelling on day one keeps the year.
     */
    private function periodEndFor(Subscription $subscription): ?Carbon
    {
        // A term already fixed by an earlier cancellation, or by the
        // application, is the authority.
        if ($subscription->ends_at !== null) {
            return $subscription->ends_at;
        }

        if ($subscription->current_period_end !== null) {
            return $subscription->current_period_end;
        }

        $start = $subscription->current_period_start ?? $subscription->created_at ?? now();

        // Rows written before the interval column existed, or by hand, carry
        // no interval; monthly is the safe reading of "unspecified".
        $interval = $subscription->interval ?? BillingInterval::Monthly;
        $end = $interval->endFrom($start);

        // A lifetime subscription has no period to run out: cancelling one can
        // only mean it never ends, so leave the term open.
        return $end === null ? null : Carbon::instance($end);
    }
}
