<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use VimaTech\LaravelQuotas\Enums\BillingInterval;
use VimaTech\LaravelQuotas\Enums\SubscriptionStatus;
use VimaTech\LaravelQuotas\Events\SubscriptionCreated;
use VimaTech\LaravelQuotas\Exceptions\AlreadySubscribedException;
use VimaTech\LaravelQuotas\Managers\QuotaManager;
use VimaTech\LaravelQuotas\Managers\SubscriptionManager;
use VimaTech\LaravelQuotas\Models\Plan;
use VimaTech\LaravelQuotas\Models\Subscription;
use VimaTech\LaravelQuotas\Models\Usage;
use VimaTech\LaravelQuotas\Support\LocalSubscriptionGuard;

final class CreateSubscriptionAction
{
    public function __construct(
        private readonly SubscriptionManager $subscriptions,
        private readonly QuotaManager $quotaManager,
    ) {}

    /**
     * @throws AlreadySubscribedException
     */
    public function execute(
        Model $billable,
        Plan $plan,
        BillingInterval $interval = BillingInterval::Monthly,
    ): Subscription {
        LocalSubscriptionGuard::ensureEnabled(__METHOD__);

        $trialDays = $this->trialDaysFor($plan);
        $status = $trialDays > 0 ? SubscriptionStatus::Trialing : SubscriptionStatus::Active;

        $subscription = DB::transaction(function () use ($billable, $plan, $status, $trialDays, $interval) {
            // Two active subscriptions for one billable is not a state this
            // package can reason about: findActive() would pick one arbitrarily
            // and the other would go on granting features nobody tracks. A
            // double-clicked form is enough to produce it, so refuse rather
            // than absorb it — checked inside the transaction to keep the
            // window between check and insert as small as it can be without a
            // database constraint.
            if ($existing = $this->subscriptions->findActive($billable)) {
                throw AlreadySubscribedException::forBillable($billable, $existing);
            }

            $start = now();

            $subscription = Subscription::query()->create([
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => $billable->getKey(),
                'plan_id' => $plan->id,
                'status' => $status,
                'interval' => $interval,
                'current_period_start' => $start,
                'current_period_end' => $interval->endFrom($start),
                'trial_ends_at' => $trialDays > 0 ? $start->copy()->addDays($trialDays) : null,
            ]);

            $this->initializeUsageRecords($billable, $plan);

            return $subscription;
        });

        // The billable had no plan a moment ago and the answer was memoised.
        $this->quotaManager->forgetPlan($billable);

        event(new SubscriptionCreated($subscription, $billable));

        return $subscription;
    }

    /**
     * A plan's own trial wins; the configured default applies to plans that
     * name none.
     *
     * The column is not nullable and defaults to 0, so `??` never fires on it —
     * the fallback has to be driven by the value, not by its presence.
     */
    private function trialDaysFor(Plan $plan): int
    {
        $planTrial = (int) ($plan->trial_days ?? 0);

        return $planTrial > 0
            ? $planTrial
            : (int) config('quotas.trial_days', 0);
    }

    private function initializeUsageRecords(Model $billable, Plan $plan): void
    {
        $limits = $plan->limits ?? [];

        foreach ($limits as $feature => $limit) {
            // Query the model directly rather than through the HasQuotas trait:
            // any Eloquent model can be billable, the trait is only a
            // convenience API.
            Usage::query()->updateOrCreate(
                [
                    'billable_type' => $billable->getMorphClass(),
                    'billable_id' => $billable->getKey(),
                    'feature' => $feature,
                ],
                [
                    'used' => 0,
                    'limit' => (int) $limit,
                    'reset_at' => now(),
                ]
            );

            $this->quotaManager->clearCache($billable, $feature);
        }
    }
}
