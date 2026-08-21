<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Contracts;

use VimaTech\LaravelQuotas\Models\Plan;

/**
 * The entitlement surface of a billable model, for type-hinting.
 *
 * Satisfied by the HasQuotas trait. Everything here works the same whichever
 * resolver owns the subscription; the lifecycle methods, which only exist for
 * local subscriptions, live in ManagesLocalSubscription instead.
 */
interface QuotaAware
{
    public function currentPlan(): ?Plan;

    public function isSubscribed(): bool;

    public function isSubscribedTo(string $planSlug): bool;

    public function onTrial(): bool;

    public function hasFeature(string $feature): bool;

    public function canUse(string $feature): bool;

    public function hasReachedLimit(string $feature): bool;

    public function hasUnlimited(string $feature): bool;

    public function incrementUsage(string $feature, int $amount = 1): void;

    public function usageOf(string $feature): int;

    public function remainingUsage(string $feature): ?int;

    /**
     * Writes to the usage counter, not to the subscription, so it is available
     * under every resolver — unlike the lifecycle methods.
     */
    public function resetUsage(string $feature): void;
}
