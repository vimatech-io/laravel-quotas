<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $billable_type
 * @property int|string $billable_id
 * @property string $feature
 * @property int $used
 * @property int $limit
 * @property Carbon|null $reset_at
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Usage extends Model
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    /**
     * Sentinel stored in the "limit" column meaning "no ceiling".
     */
    public const UNLIMITED = -1;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'used' => 'integer',
            'limit' => 'integer',
            'reset_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function getTable(): string
    {
        return config('quotas.table_names.usages', 'quota_usages');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function billable(): MorphTo
    {
        return $this->morphTo('billable');
    }

    public function isUnlimited(): bool
    {
        return $this->limit === self::UNLIMITED;
    }

    public function hasReachedLimit(): bool
    {
        if ($this->isUnlimited()) {
            return false;
        }

        return $this->used >= $this->limit;
    }

    public function remaining(): int
    {
        if ($this->isUnlimited()) {
            return PHP_INT_MAX;
        }

        return max(0, $this->limit - $this->used);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForFeature(Builder $query, string $feature): Builder
    {
        return $query->where('feature', $feature);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForBillable(Builder $query, Model $billable): Builder
    {
        return $query->where('billable_type', $billable->getMorphClass())
            ->where('billable_id', $billable->getKey());
    }
}
