<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Managers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use VimaTech\LaravelQuotas\Enums\SubscriptionStatus;
use VimaTech\LaravelQuotas\Exceptions\NoActiveSubscriptionException;
use VimaTech\LaravelQuotas\Models\Subscription;

/**
 * Reads and writes the subscriptions this package stores itself.
 *
 * Only meaningful when entitlements resolve locally. With a Cashier-backed
 * resolver the authoritative subscription lives in Cashier's tables and this
 * manager's table stays empty.
 */
final class SubscriptionManager
{
    /**
     * Get the active subscription for a billable model.
     *
     * @throws NoActiveSubscriptionException
     */
    public function getActive(Model $billable): Subscription
    {
        $subscription = Subscription::query()
            ->forBillable($billable)
            ->active()
            ->latest()
            ->first();

        if (! $subscription) {
            throw NoActiveSubscriptionException::forBillable($billable);
        }

        return $subscription;
    }

    /**
     * Get the active subscription or null.
     */
    public function findActive(Model $billable): ?Subscription
    {
        return Subscription::query()
            ->forBillable($billable)
            ->active()
            ->latest()
            ->first();
    }

    /**
     * Get all subscriptions for a billable.
     *
     * @return Collection<int, Subscription>
     */
    public function all(Model $billable): Collection
    {
        return Subscription::query()
            ->forBillable($billable)
            ->with('plan')
            ->latest()
            ->get();
    }

    /**
     * Check if a billable is on a trial.
     */
    public function isOnTrial(Model $billable): bool
    {
        return Subscription::query()
            ->forBillable($billable)
            ->where('status', SubscriptionStatus::Trialing)
            ->where('trial_ends_at', '>', now())
            ->exists();
    }

    /**
     * The most recent cancelled subscription, or null when there is none.
     *
     * This is what resuming operates on: findActive() cannot serve here, since
     * a cancelled-at-period-end subscription is still active until it ends.
     */
    public function findLatestCancelled(Model $billable): ?Subscription
    {
        return Subscription::query()
            ->forBillable($billable)
            ->where('status', SubscriptionStatus::Cancelled)
            ->latest()
            ->first();
    }

    /**
     * Get a subscription by its primary key.
     */
    public function find(int|string $id): ?Subscription
    {
        return Subscription::query()->find($id);
    }
}
