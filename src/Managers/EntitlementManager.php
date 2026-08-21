<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Managers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use VimaTech\LaravelQuotas\Actions\CancelSubscriptionAction;
use VimaTech\LaravelQuotas\Actions\ChangePlanAction;
use VimaTech\LaravelQuotas\Actions\CreateSubscriptionAction;
use VimaTech\LaravelQuotas\Actions\IncrementUsageAction;
use VimaTech\LaravelQuotas\Actions\ResumeSubscriptionAction;
use VimaTech\LaravelQuotas\Enums\BillingInterval;
use VimaTech\LaravelQuotas\Exceptions\AlreadySubscribedException;
use VimaTech\LaravelQuotas\Exceptions\LocalSubscriptionsDisabledException;
use VimaTech\LaravelQuotas\Exceptions\SubscriptionNotCancelledException;
use VimaTech\LaravelQuotas\Exceptions\UsageLimitExceededException;
use VimaTech\LaravelQuotas\Models\Plan;
use VimaTech\LaravelQuotas\Models\Subscription;

/**
 * The entry point behind the Quotas facade.
 */
final class EntitlementManager
{
    public function __construct(
        private readonly PlanManager $planManager,
        private readonly SubscriptionManager $subscriptionManager,
        private readonly QuotaManager $quotaManager,
    ) {}

    /**
     * All active plans, ordered.
     *
     * @return Collection<int, Plan>
     */
    public function plans(): Collection
    {
        return $this->planManager->all();
    }

    public function plan(string $slug): Plan
    {
        return $this->planManager->findBySlug($slug);
    }

    /**
     * The plan currently backing a billable, wherever its subscription lives.
     */
    public function currentPlan(Model $billable): ?Plan
    {
        return $this->quotaManager->currentPlan($billable);
    }

    /**
     * Subscribe a billable in the local subscriptions table.
     *
     * @throws LocalSubscriptionsDisabledException
     * @throws AlreadySubscribedException
     */
    public function subscribe(
        Model $billable,
        string $planSlug,
        BillingInterval $interval = BillingInterval::Monthly,
    ): Subscription {
        $plan = $this->planManager->findSellableBySlug($planSlug);

        return app(CreateSubscriptionAction::class)->execute($billable, $plan, $interval);
    }

    /**
     * Cancel a billable's local subscription.
     *
     * @throws LocalSubscriptionsDisabledException
     */
    public function cancel(Model $billable, bool $immediately = false): Subscription
    {
        $subscription = $this->subscriptionManager->getActive($billable);

        return app(CancelSubscriptionAction::class)->execute($subscription, $immediately);
    }

    /**
     * Swap a billable's local subscription to another plan.
     *
     * @throws LocalSubscriptionsDisabledException
     */
    public function swap(Model $billable, string $newPlanSlug): Subscription
    {
        $subscription = $this->subscriptionManager->getActive($billable);
        $newPlan = $this->planManager->findSellableBySlug($newPlanSlug);

        return app(ChangePlanAction::class)->execute($subscription, $newPlan);
    }

    /**
     * Resume a billable's cancelled local subscription.
     *
     * @throws LocalSubscriptionsDisabledException
     * @throws SubscriptionNotCancelledException
     */
    public function resume(Model $billable): Subscription
    {
        $subscription = $this->subscriptionManager->findLatestCancelled($billable);

        if ($subscription === null) {
            throw SubscriptionNotCancelledException::noneToResume($billable);
        }

        return app(ResumeSubscriptionAction::class)->execute($subscription);
    }

    public function canUse(Model $billable, string $feature): bool
    {
        return $this->quotaManager->canUse($billable, $feature);
    }

    /**
     * @throws UsageLimitExceededException
     */
    public function increment(Model $billable, string $feature, int $amount = 1): void
    {
        app(IncrementUsageAction::class)->execute($billable, $feature, $amount);
    }

    /**
     * How much of a feature is left, or null when it has no ceiling.
     */
    public function remaining(Model $billable, string $feature): ?int
    {
        return $this->quotaManager->remaining($billable, $feature);
    }

    public function planManager(): PlanManager
    {
        return $this->planManager;
    }

    public function subscriptionManager(): SubscriptionManager
    {
        return $this->subscriptionManager;
    }

    public function quotaManager(): QuotaManager
    {
        return $this->quotaManager;
    }
}
