<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Managers;

use Illuminate\Support\Collection;
use VimaTech\LaravelQuotas\DTOs\PlanData;
use VimaTech\LaravelQuotas\Exceptions\PlanNotFoundException;
use VimaTech\LaravelQuotas\Models\Plan;

final class PlanManager
{
    /**
     * Catalogue snapshot for price lookups, memoised for the current request.
     *
     * @var Collection<int, Plan>|null
     */
    private ?Collection $catalogue = null;

    /**
     * Get all active plans ordered by sort_order.
     *
     * @return Collection<int, Plan>
     */
    public function all(): Collection
    {
        return Plan::query()->active()->ordered()->get();
    }

    /**
     * Find a plan by its slug.
     *
     * @throws PlanNotFoundException
     */
    public function findBySlug(string $slug): Plan
    {
        $plan = Plan::query()->where('slug', $slug)->first();

        if (! $plan) {
            throw PlanNotFoundException::withSlug($slug);
        }

        return $plan;
    }

    /**
     * Find a plan that is still on sale.
     *
     * Kept apart from findBySlug() on purpose: reading a plan and selling one
     * are different questions. A subscriber grandfathered onto a retired plan
     * must keep resolving it, so findBySlug() stays permissive — but nothing
     * should be able to subscribe to a plan you have taken off the catalogue,
     * least of all a slug posted to the portal.
     *
     * @throws PlanNotFoundException
     */
    public function findSellableBySlug(string $slug): Plan
    {
        $plan = Plan::query()->where('slug', $slug)->active()->first();

        if (! $plan) {
            throw PlanNotFoundException::notSellable($slug);
        }

        return $plan;
    }

    /**
     * Find a plan by its ID.
     *
     * @throws PlanNotFoundException
     */
    public function findById(int $id): Plan
    {
        $plan = Plan::query()->find($id);

        if (! $plan) {
            throw PlanNotFoundException::withId($id);
        }

        return $plan;
    }

    /**
     * Find the plan sold under any of the given provider price ids.
     *
     * Matching happens in PHP rather than through a JSON query: plan catalogues
     * are small and this keeps the lookup portable across every database
     * Laravel supports. Soft-deleted plans are included on purpose — someone
     * still subscribed to a retired plan must keep the entitlements they paid
     * for until their subscription actually ends.
     *
     * @param  array<int, string>  $priceIds
     */
    public function findByGatewayPrice(string $gateway, array $priceIds): ?Plan
    {
        if ($priceIds === []) {
            return null;
        }

        $this->catalogue ??= Plan::query()->withTrashed()->get();

        return $this->catalogue
            ->first(fn (Plan $plan): bool => $plan->matchesGatewayPrice($gateway, $priceIds));
    }

    /**
     * Drop the memoised catalogue. Called after any write to a plan.
     */
    public function flush(): void
    {
        $this->catalogue = null;
    }

    /**
     * Create a new plan.
     */
    public function create(PlanData $data): Plan
    {
        $this->flush();

        return Plan::query()->create([
            'name' => $data->name,
            'slug' => $data->slug,
            'description' => $data->description,
            'monthly_price' => $data->monthlyPrice,
            'yearly_price' => $data->yearlyPrice,
            'currency' => $data->currency,
            'features' => $data->features,
            'limits' => $data->limits,
            'gateway_prices' => $data->gatewayPrices,
            'metadata' => $data->metadata,
            'trial_days' => $data->trialDays ?? 0,
            'sort_order' => $data->sortOrder,
            'is_active' => true,
        ]);
    }

    /**
     * Update a plan.
     */
    public function update(Plan $plan, PlanData $data): Plan
    {
        $this->flush();

        $plan->update([
            'name' => $data->name,
            'slug' => $data->slug,
            'description' => $data->description,
            'monthly_price' => $data->monthlyPrice,
            'yearly_price' => $data->yearlyPrice,
            'currency' => $data->currency,
            'features' => $data->features,
            'limits' => $data->limits,
            'gateway_prices' => $data->gatewayPrices,
            'metadata' => $data->metadata,
            'trial_days' => $data->trialDays ?? 0,
            'sort_order' => $data->sortOrder,
        ]);

        return $plan->fresh();
    }

    /**
     * Deactivate a plan.
     */
    public function deactivate(Plan $plan): Plan
    {
        $this->flush();

        $plan->update(['is_active' => false]);

        return $plan->fresh();
    }

    /**
     * Activate a plan.
     */
    public function activate(Plan $plan): Plan
    {
        $this->flush();

        $plan->update(['is_active' => true]);

        return $plan->fresh();
    }
}
