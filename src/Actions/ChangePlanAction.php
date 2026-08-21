<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use VimaTech\LaravelQuotas\Events\PlanChanged;
use VimaTech\LaravelQuotas\Managers\QuotaManager;
use VimaTech\LaravelQuotas\Models\Plan;
use VimaTech\LaravelQuotas\Models\Subscription;
use VimaTech\LaravelQuotas\Models\Usage;
use VimaTech\LaravelQuotas\Support\LocalSubscriptionGuard;

final class ChangePlanAction
{
    public function __construct(
        private readonly QuotaManager $quotaManager,
    ) {}

    public function execute(Subscription $subscription, Plan $newPlan): Subscription
    {
        LocalSubscriptionGuard::ensureEnabled(__METHOD__);

        $oldPlan = $subscription->plan;
        $billable = $subscription->billable;

        // The plan swap and the limits it implies are one change. Committing
        // the first without the second leaves a billable on a plan whose
        // ceilings never arrived.
        DB::transaction(function () use ($subscription, $newPlan, $billable): void {
            $subscription->update(['plan_id' => $newPlan->id]);

            if ($billable instanceof Model) {
                $this->syncUsageLimits($billable, $newPlan);
            }
        });

        if ($billable instanceof Model) {
            // Outside the limits loop and outside the transaction, because it
            // depends on neither: the memoised plan is stale the moment
            // plan_id changes, whether or not the new plan has a single limit
            // to iterate over.
            $this->quotaManager->forgetPlan($billable);
        }

        event(new PlanChanged($subscription, $oldPlan, $newPlan));

        return $subscription->fresh();
    }

    /**
     * Bring the billable's usage rows in line with the plan it is now on.
     *
     * Both directions matter. Features the new plan grants take its ceilings;
     * features it does not grant have their rows revoked, so a downgrade stops
     * advertising an allowance the customer no longer has.
     */
    private function syncUsageLimits(Model $billable, Plan $newPlan): void
    {
        $limits = $newPlan->limits ?? [];

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
                ['limit' => (int) $limit]
            );

            $this->quotaManager->clearCache($billable, $feature);
        }

        $this->revokeDroppedFeatures($billable, array_keys($limits));
    }

    /**
     * Drop the usage rows for features the new plan does not meter.
     *
     * The counters are deleted rather than zeroed: the row exists to hold an
     * allowance, and there is no allowance any more. A later upgrade recreates
     * it from the plan, which is the only source of truth for a ceiling
     * anyway.
     *
     * @param  array<int, string>  $keptFeatures
     */
    private function revokeDroppedFeatures(Model $billable, array $keptFeatures): void
    {
        $dropped = Usage::query()
            ->forBillable($billable)
            ->when($keptFeatures !== [], fn ($query) => $query->whereNotIn('feature', $keptFeatures))
            ->pluck('feature');

        if ($dropped->isEmpty()) {
            return;
        }

        Usage::query()
            ->forBillable($billable)
            ->whereIn('feature', $dropped)
            ->delete();

        foreach ($dropped as $feature) {
            $this->quotaManager->clearCache($billable, $feature);
        }
    }
}
