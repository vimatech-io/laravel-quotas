<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Contracts;

/**
 * Marks a resolver that treats this package's own subscriptions table as one
 * of its sources.
 *
 * Writes to the local table — subscribe(), swapPlan(), cancelSubscription() —
 * are only allowed while the active resolver declares this interface. The
 * shipped local resolver does; a resolver reading Cashier alone must not,
 * because a local subscription nobody pays for is exactly the failure this
 * guard exists to prevent.
 *
 * Implement it on a composite resolver when part of your audience genuinely
 * lives in the local table: a Paddle resolver with an AppSumo-lifetime
 * fallback, a Stripe resolver with manually granted internal tenants.
 */
interface LocalSubscriptionSource {}
