<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Contracts;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use VimaTech\LaravelQuotas\Models\Plan;

/**
 * Answers "what is this billable entitled to?" without owning the payment flow.
 *
 * This package deliberately does not charge anyone. Money is handled by Laravel
 * Cashier (or by nothing at all, for manually managed accounts); a resolver is
 * the thin adapter that reads whichever system is authoritative and reports the
 * plan behind it. Quotas and feature gates are then enforced locally.
 */
interface SubscriptionResolverInterface
{
    /**
     * The plan currently backing this billable, or null when it has none.
     */
    public function resolvePlan(Model $billable): ?Plan;

    /**
     * Whether the billable holds a subscription that grants access, including
     * one on trial or inside its grace period.
     */
    public function isSubscribed(Model $billable): bool;

    /**
     * Whether the billable is currently on a trial.
     */
    public function onTrial(Model $billable): bool;

    /**
     * The billing anniversary quota periods are measured from — normally the
     * moment the subscription started.
     *
     * Returning null means there is no anchor to work from, and periods fall
     * back to the calendar. Deliberately derived from data already held
     * locally: quota resets must never depend on a provider API call.
     */
    public function anchor(Model $billable): ?CarbonImmutable;
}
