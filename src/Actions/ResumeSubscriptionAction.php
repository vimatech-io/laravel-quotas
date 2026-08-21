<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Actions;

use Illuminate\Database\Eloquent\Model;
use VimaTech\LaravelQuotas\Enums\BillingInterval;
use VimaTech\LaravelQuotas\Enums\SubscriptionStatus;
use VimaTech\LaravelQuotas\Exceptions\SubscriptionNotCancelledException;
use VimaTech\LaravelQuotas\Managers\QuotaManager;
use VimaTech\LaravelQuotas\Models\Subscription;
use VimaTech\LaravelQuotas\Support\LocalSubscriptionGuard;

final class ResumeSubscriptionAction
{
    public function __construct(
        private readonly QuotaManager $quotaManager,
    ) {}

    public function execute(Subscription $subscription): Subscription
    {
        LocalSubscriptionGuard::ensureEnabled(__METHOD__);

        if (! $subscription->isCancelled()) {
            throw SubscriptionNotCancelledException::forSubscription($subscription);
        }

        $start = now();
        $interval = $subscription->interval ?? BillingInterval::Monthly;

        // Resuming inside the grace period keeps the period already paid for;
        // resuming after it has lapsed starts a fresh one, so the customer is
        // not handed a period that ended in the past.
        $periodEnd = $subscription->current_period_end?->isFuture()
            ? $subscription->current_period_end
            : $interval->endFrom($start);

        $subscription->update([
            'status' => SubscriptionStatus::Active,
            'cancelled_at' => null,
            'ends_at' => null,
            'past_due_since' => null,
            'current_period_start' => $subscription->current_period_end?->isFuture()
                ? $subscription->current_period_start
                : $start,
            'current_period_end' => $periodEnd,
        ]);

        $billable = $subscription->billable;

        if ($billable instanceof Model) {
            $this->quotaManager->forgetPlan($billable);
        }

        return $subscription->fresh();
    }
}
