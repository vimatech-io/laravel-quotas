<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Resolvers;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use VimaTech\LaravelQuotas\Contracts\SubscriptionResolverInterface;
use VimaTech\LaravelQuotas\Exceptions\BillableNotCashierReadyException;
use VimaTech\LaravelQuotas\Managers\PlanManager;
use VimaTech\LaravelQuotas\Models\Plan;

/**
 * Shared plumbing for the Cashier-backed resolvers.
 *
 * Cashier is the authority on whether a subscription is valid; this class only
 * translates its answer into a local Plan, by matching the price identifiers
 * Cashier stores against each plan's `gateway_prices` map.
 *
 * Nothing here type-hints a Cashier class. Cashier is an optional dependency,
 * so the subscription is read through the narrow surface both Cashier Stripe
 * and Cashier Paddle expose on their Eloquent models.
 */
abstract class CashierResolver implements SubscriptionResolverInterface
{
    public function __construct(
        protected readonly PlanManager $plans,
        protected readonly string $subscriptionType = 'default',
    ) {}

    /**
     * The gateway key these subscriptions are stored under in `gateway_prices`.
     */
    abstract protected function gateway(): string;

    /**
     * Pull the price identifiers out of a Cashier subscription.
     *
     * @return array<int, string>
     */
    abstract protected function priceIds(Model $subscription): array;

    public function resolvePlan(Model $billable): ?Plan
    {
        $subscription = $this->validSubscription($billable);

        if ($subscription === null) {
            return null;
        }

        $priceIds = $this->priceIds($subscription);

        if ($priceIds === []) {
            return null;
        }

        return $this->plans->findByGatewayPrice($this->gateway(), $priceIds);
    }

    public function isSubscribed(Model $billable): bool
    {
        return $this->validSubscription($billable) !== null;
    }

    public function onTrial(Model $billable): bool
    {
        $subscription = $this->validSubscription($billable);

        if ($subscription === null) {
            return false;
        }

        return method_exists($subscription, 'onTrial') && (bool) $subscription->onTrial();
    }

    /**
     * The date quota periods are measured from.
     *
     * The current billing period start is the right answer whenever the
     * provider reports one: it is the date the customer is actually charged
     * on. Creation date only coincides with it until something moves the
     * billing cycle — a plan change with proration resets the period at the
     * provider while created_at stays where it was, and from then on the quota
     * would come back on a different day from the invoice, permanently.
     *
     * Both columns are already on Cashier's local table, so this stays a read
     * of data we hold. The contract forbids a network call here.
     */
    public function anchor(Model $billable): ?CarbonImmutable
    {
        $subscription = $this->validSubscription($billable);

        if ($subscription === null) {
            return null;
        }

        foreach (['current_period_start', 'created_at'] as $attribute) {
            $value = $subscription->getAttribute($attribute);

            if ($value instanceof DateTimeInterface) {
                return CarbonImmutable::instance($value);
            }
        }

        return null;
    }

    /**
     * The billable's Cashier subscription, or null when it grants no access.
     *
     * `valid()` is Cashier's own verdict: it covers active, trialing and
     * still-in-grace-period subscriptions, and excludes past due and cancelled
     * ones. Re-deriving that from raw statuses here would drift from Cashier.
     *
     * @throws BillableNotCashierReadyException
     */
    protected function validSubscription(Model $billable): ?Model
    {
        if (! method_exists($billable, 'subscription')) {
            throw BillableNotCashierReadyException::for($billable, $this->gateway());
        }

        $subscription = $billable->subscription($this->subscriptionType);

        // valid() is the surface every Cashier subscription model exposes; an
        // object without it is not one, whatever else it may be.
        if (! $subscription instanceof Model || ! method_exists($subscription, 'valid')) {
            return null;
        }

        if (! $subscription->valid()) {
            return null;
        }

        return $subscription;
    }

    /**
     * The subscription's price rows, or nothing when it has none loaded.
     *
     * @return iterable<mixed>
     */
    protected function items(Model $subscription): iterable
    {
        $items = $subscription->getAttribute('items');

        return is_iterable($items) ? $items : [];
    }

    /**
     * Read a price identifier off a subscription item, tolerating either
     * Eloquent models or plain objects depending on the Cashier version.
     */
    protected function attribute(mixed $item, string $key): ?string
    {
        $value = $item instanceof Model
            ? $item->getAttribute($key)
            : ($item->{$key} ?? null);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
