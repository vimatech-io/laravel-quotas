<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Managers;

use Illuminate\Database\Eloquent\Model;
use VimaTech\LaravelQuotas\Contracts\SubscriptionResolverInterface;
use VimaTech\LaravelQuotas\Models\Plan;
use VimaTech\LaravelQuotas\Models\Usage;
use VimaTech\LaravelQuotas\Quota\UsageCache;
use VimaTech\LaravelQuotas\Quota\UsageResetter;

final class QuotaManager
{
    /**
     * Plans already resolved during this request, keyed by billable.
     *
     * Feature gates are asked the same question several times per request — a
     * middleware, a Blade check, the action itself — and each answer would
     * otherwise cost a fresh subscription lookup. The binding is
     * request-scoped, so this lives exactly as long as the answer stays true.
     *
     * @var array<string, Plan|null>
     */
    private array $plans = [];

    /**
     * Subscription verdicts already resolved during this request.
     *
     * The Cashier resolvers answer each of these with a fresh subscription
     * lookup, and a gated page asks all three — is there a subscription, is it
     * a trial, which plan — so without this the same row is fetched several
     * times per request.
     *
     * @var array<string, bool>
     */
    private array $subscribed = [];

    /** @var array<string, bool> */
    private array $trialing = [];

    public function __construct(
        private readonly SubscriptionResolverInterface $resolver,
        private readonly UsageCache $cache,
        private readonly UsageResetter $resetter,
    ) {}

    /**
     * Whether a billable may use a feature right now: its plan grants the
     * feature and the allowance is not spent.
     */
    public function canUse(Model $billable, string $feature): bool
    {
        $plan = $this->currentPlan($billable);

        if ($plan === null || ! $plan->hasFeature($feature)) {
            return false;
        }

        return ! $this->hasReachedLimit($billable, $feature);
    }

    /**
     * Whether the current plan grants the feature at all, regardless of usage.
     */
    public function hasFeature(Model $billable, string $feature): bool
    {
        return $this->currentPlan($billable)?->hasFeature($feature) ?? false;
    }

    /**
     * Whether the allowance for a feature is spent.
     *
     * A plan that does not grant the feature counts as reached, and so does
     * having no plan at all: a caller that gates on this alone must not let an
     * unentitled billable through, and it has to agree with remaining(), which
     * reports 0 in the same situations.
     */
    public function hasReachedLimit(Model $billable, string $feature): bool
    {
        $plan = $this->currentPlan($billable);

        if ($plan === null || ! $plan->hasFeature($feature)) {
            return true;
        }

        $limit = $plan->getLimit($feature);

        if ($limit === null || $limit === Usage::UNLIMITED) {
            return false;
        }

        return $this->getUsage($billable, $feature) >= $limit;
    }

    /**
     * Current consumption of a feature for the period in progress.
     */
    public function getUsage(Model $billable, string $feature): int
    {
        return $this->cache->remember($billable, $feature, function () use ($billable, $feature): int {
            $usage = Usage::query()
                ->forBillable($billable)
                ->forFeature($feature)
                ->first();

            if ($usage === null) {
                return 0;
            }

            // Roll the counter over the moment anyone looks at it, so a quota
            // comes back without waiting for the scheduler to run.
            if ($this->resetter->resetIfRolledOver($billable, $usage)) {
                return 0;
            }

            return $usage->used;
        });
    }

    /**
     * How much of a feature is left, or null when the plan grants it without
     * a ceiling.
     */
    public function remaining(Model $billable, string $feature): ?int
    {
        $plan = $this->currentPlan($billable);

        if ($plan === null || ! $plan->hasFeature($feature)) {
            return 0;
        }

        $limit = $plan->getLimit($feature);

        if ($limit === null || $limit === Usage::UNLIMITED) {
            return null;
        }

        return max(0, $limit - $this->getUsage($billable, $feature));
    }

    /**
     * Whether a feature is granted without a ceiling.
     */
    public function isUnlimited(Model $billable, string $feature): bool
    {
        $plan = $this->currentPlan($billable);

        if ($plan === null || ! $plan->hasFeature($feature)) {
            return false;
        }

        $limit = $plan->getLimit($feature);

        return $limit === null || $limit === Usage::UNLIMITED;
    }

    /**
     * Clear consumption of one feature, independently of its period.
     */
    public function resetUsage(Model $billable, string $feature): void
    {
        Usage::query()
            ->forBillable($billable)
            ->forFeature($feature)
            ->update(['used' => 0, 'reset_at' => now()]);

        $this->cache->forget($billable, $feature);
    }

    /**
     * Clear consumption of every feature for a billable.
     */
    public function resetAllUsage(Model $billable): void
    {
        $features = Usage::query()
            ->forBillable($billable)
            ->pluck('feature');

        Usage::query()
            ->forBillable($billable)
            ->update(['used' => 0, 'reset_at' => now()]);

        foreach ($features as $feature) {
            $this->cache->forget($billable, $feature);
        }
    }

    public function clearCache(Model $billable, string $feature): void
    {
        $this->cache->forget($billable, $feature);
    }

    /**
     * Forget the memoised plan for a billable, after its subscription changed.
     */
    public function forgetPlan(Model $billable): void
    {
        $key = $this->memoKey($billable);

        unset($this->plans[$key], $this->subscribed[$key], $this->trialing[$key]);
    }

    /**
     * Drop every memoised entitlement answer. Called when the request ends.
     */
    public function flush(): void
    {
        $this->plans = [];
        $this->subscribed = [];
        $this->trialing = [];
    }

    /**
     * The plan currently backing this billable, whatever system holds it.
     */
    public function currentPlan(Model $billable): ?Plan
    {
        $key = $this->memoKey($billable);

        // Checked with array_key_exists, not ??=: "no plan" is a legitimate
        // answer and the most frequent one on a gated route, so it has to be
        // memoised too rather than re-queried on every check.
        if (! array_key_exists($key, $this->plans)) {
            $this->plans[$key] = $this->resolver->resolvePlan($billable);
        }

        return $this->plans[$key];
    }

    public function isSubscribed(Model $billable): bool
    {
        return $this->memo($this->subscribed, $billable, fn (): bool => $this->resolver->isSubscribed($billable));
    }

    public function onTrial(Model $billable): bool
    {
        return $this->memo($this->trialing, $billable, fn (): bool => $this->resolver->onTrial($billable));
    }

    /**
     * @param  array<string, bool>  $store
     * @param  callable(): bool  $resolve
     */
    private function memo(array &$store, Model $billable, callable $resolve): bool
    {
        $key = $this->memoKey($billable);

        if (! array_key_exists($key, $store)) {
            $store[$key] = $resolve();
        }

        return $store[$key];
    }

    private function memoKey(Model $billable): string
    {
        return $billable->getMorphClass().':'.$billable->getKey();
    }
}
