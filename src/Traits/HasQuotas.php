<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Traits;

use Illuminate\Database\Eloquent\Relations\MorphMany;
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
use VimaTech\LaravelQuotas\Managers\PlanManager;
use VimaTech\LaravelQuotas\Managers\QuotaManager;
use VimaTech\LaravelQuotas\Managers\SubscriptionManager;
use VimaTech\LaravelQuotas\Models\Plan;
use VimaTech\LaravelQuotas\Models\Subscription;
use VimaTech\LaravelQuotas\Models\Usage;

/**
 * Feature gates and usage quotas for a billable model.
 *
 * The entitlement side (hasFeature, canUse, incrementUsage, remainingUsage)
 * works the same whoever holds the subscription. The lifecycle side (subscribe,
 * cancelSubscription, swapPlan, resumeSubscription) writes to this package's own
 * subscriptions table and is only available when entitlements resolve locally;
 * with a Cashier-backed resolver those calls throw, because Cashier is the one
 * that must create and cancel subscriptions.
 */
trait HasQuotas
{
    /**
     * @return MorphMany<Subscription, $this>
     */
    public function subscriptions(): MorphMany
    {
        return $this->morphMany(Subscription::class, 'billable');
    }

    /**
     * @return MorphMany<Usage, $this>
     */
    public function usages(): MorphMany
    {
        return $this->morphMany(Usage::class, 'billable');
    }

    /**
     * Subscribe this billable to a plan in the local subscriptions table.
     *
     * @throws LocalSubscriptionsDisabledException
     * @throws AlreadySubscribedException
     */
    public function subscribe(
        string $planSlug,
        BillingInterval $interval = BillingInterval::Monthly,
    ): Subscription {
        $plan = app(PlanManager::class)->findSellableBySlug($planSlug);

        return app(CreateSubscriptionAction::class)->execute($this, $plan, $interval);
    }

    /**
     * Cancel the current local subscription.
     *
     * @throws LocalSubscriptionsDisabledException
     */
    public function cancelSubscription(bool $immediately = false): Subscription
    {
        $subscription = app(SubscriptionManager::class)->getActive($this);

        return app(CancelSubscriptionAction::class)->execute($subscription, $immediately);
    }

    /**
     * Resume a cancelled local subscription.
     *
     * @throws LocalSubscriptionsDisabledException
     */
    public function resumeSubscription(): Subscription
    {
        $subscription = app(SubscriptionManager::class)->findLatestCancelled($this);

        // A package exception, not firstOrFail(): ModelNotFoundException reads
        // as a routing 404 to most handlers, which is nonsense for a billing
        // action a caller may legitimately attempt twice.
        if ($subscription === null) {
            throw SubscriptionNotCancelledException::noneToResume($this);
        }

        return app(ResumeSubscriptionAction::class)->execute($subscription);
    }

    /**
     * Swap the local subscription to a different plan.
     *
     * @throws LocalSubscriptionsDisabledException
     */
    public function swapPlan(string $newPlanSlug): Subscription
    {
        $subscription = app(SubscriptionManager::class)->getActive($this);
        $newPlan = app(PlanManager::class)->findSellableBySlug($newPlanSlug);

        return app(ChangePlanAction::class)->execute($subscription, $newPlan);
    }

    /**
     * The plan currently backing this billable, wherever its subscription lives.
     */
    public function currentPlan(): ?Plan
    {
        return app(QuotaManager::class)->currentPlan($this);
    }

    /**
     * The local subscription record, when there is one.
     *
     * Returns null under a Cashier-backed resolver. Ask Cashier instead.
     */
    public function currentSubscription(): ?Subscription
    {
        return app(SubscriptionManager::class)->findActive($this);
    }

    public function isSubscribed(): bool
    {
        return app(QuotaManager::class)->isSubscribed($this);
    }

    public function isSubscribedTo(string $planSlug): bool
    {
        return $this->isSubscribed() && $this->currentPlan()?->slug === $planSlug;
    }

    public function onTrial(): bool
    {
        return app(QuotaManager::class)->onTrial($this);
    }

    /**
     * Whether the current plan grants a feature at all.
     */
    public function hasFeature(string $feature): bool
    {
        return app(QuotaManager::class)->hasFeature($this, $feature);
    }

    /**
     * Whether the feature is granted and its allowance is not spent.
     */
    public function canUse(string $feature): bool
    {
        return app(QuotaManager::class)->canUse($this, $feature);
    }

    public function hasReachedLimit(string $feature): bool
    {
        return app(QuotaManager::class)->hasReachedLimit($this, $feature);
    }

    /**
     * Consume quota for a feature.
     *
     * @throws UsageLimitExceededException
     */
    public function incrementUsage(string $feature, int $amount = 1): void
    {
        app(IncrementUsageAction::class)->execute($this, $feature, $amount);
    }

    /**
     * Clear consumption of a feature, independently of its period.
     */
    public function resetUsage(string $feature): void
    {
        app(QuotaManager::class)->resetUsage($this, $feature);
    }

    /**
     * Current consumption of a feature.
     */
    public function usageOf(string $feature): int
    {
        return app(QuotaManager::class)->getUsage($this, $feature);
    }

    /**
     * How much of a feature is left, or null when it has no ceiling.
     */
    public function remainingUsage(string $feature): ?int
    {
        return app(QuotaManager::class)->remaining($this, $feature);
    }

    /**
     * Whether a feature is granted without a ceiling.
     */
    public function hasUnlimited(string $feature): bool
    {
        return app(QuotaManager::class)->isUnlimited($this, $feature);
    }
}
