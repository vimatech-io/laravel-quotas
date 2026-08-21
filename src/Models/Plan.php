<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property int $monthly_price
 * @property int|null $yearly_price
 * @property string $currency
 * @property array<int, string>|null $features
 * @property array<string, int>|null $limits
 * @property array<string, mixed>|null $gateway_prices
 * @property array<string, mixed>|null $metadata
 * @property int $trial_days
 * @property int $sort_order
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Plan extends Model
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'monthly_price' => 'integer',
            'yearly_price' => 'integer',
            'features' => 'array',
            'limits' => 'array',
            'gateway_prices' => 'array',
            'metadata' => 'array',
            'is_active' => 'boolean',
            'trial_days' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function getTable(): string
    {
        return config('quotas.table_names.plans', 'quota_plans');
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'plan_id');
    }

    public function hasFeature(string $feature): bool
    {
        return in_array($feature, $this->features ?? [], true);
    }

    public function getLimit(string $feature): ?int
    {
        $limits = $this->limits ?? [];

        return isset($limits[$feature]) ? (int) $limits[$feature] : null;
    }

    public function isUnlimited(string $feature): bool
    {
        return $this->getLimit($feature) === Usage::UNLIMITED;
    }

    /**
     * The provider price identifiers this plan is sold under.
     *
     * A plan usually has more than one — a monthly price and a yearly one both
     * grant the same features — so the value is normalised to a list whether it
     * was stored as a single string or an array.
     *
     * @return array<int, string>
     */
    public function pricesFor(string $gateway): array
    {
        $prices = $this->gateway_prices[$gateway] ?? [];

        return array_values(array_filter(
            is_array($prices) ? $prices : [$prices],
            static fn (mixed $price): bool => is_string($price) && $price !== '',
        ));
    }

    /**
     * Whether this plan is sold under any of the given provider price ids.
     *
     * @param  array<int, string>  $priceIds
     */
    public function matchesGatewayPrice(string $gateway, array $priceIds): bool
    {
        return array_intersect($this->pricesFor($gateway), $priceIds) !== [];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order');
    }
}
