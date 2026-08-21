<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use VimaTech\LaravelQuotas\Enums\BillingInterval;
use VimaTech\LaravelQuotas\Enums\SubscriptionStatus;

/**
 * @property int $id
 * @property string $billable_type
 * @property int|string $billable_id
 * @property int $plan_id
 * @property SubscriptionStatus $status
 * @property BillingInterval $interval
 * @property Carbon|null $current_period_start
 * @property Carbon|null $current_period_end
 * @property Carbon|null $past_due_since
 * @property Carbon|null $trial_ends_at
 * @property Carbon|null $ends_at
 * @property Carbon|null $cancelled_at
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Plan|null $plan
 * @property-read Model|null $billable
 */
class Subscription extends Model
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'interval' => BillingInterval::class,
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'past_due_since' => 'datetime',
            'trial_ends_at' => 'datetime',
            'ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function getTable(): string
    {
        return config('quotas.table_names.subscriptions', 'quota_subscriptions');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function billable(): MorphTo
    {
        return $this->morphTo('billable');
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    /**
     * Whether this subscription grants its plan right now.
     *
     * This is the PHP twin of scopeActive(), and the two must always agree:
     * the resolver reaches entitlements through the scope, so a subscription
     * the scope accepts but this method rejects would hand out access nobody
     * is entitled to — and the other way round would revoke access someone
     * paid for. Both are stated once, here and in the scope, against the same
     * four rules.
     */
    public function isActive(): bool
    {
        if ($this->hasEnded()) {
            return false;
        }

        return match ($this->status) {
            SubscriptionStatus::Active => true,
            SubscriptionStatus::Trialing => $this->trial_ends_at?->isFuture() ?? false,
            // A cancelled subscription still owes the period already paid for.
            SubscriptionStatus::Cancelled => $this->ends_at?->isFuture() ?? false,
            SubscriptionStatus::PastDue => $this->isWithinPastDueGrace(),
            SubscriptionStatus::Expired => false,
        };
    }

    /**
     * Whether the term this subscription was sold for is over, whatever its
     * status still says.
     */
    public function hasEnded(): bool
    {
        return $this->ends_at !== null && $this->ends_at->isPast();
    }

    /**
     * Whether a failed payment is still inside the retry window.
     */
    public function isWithinPastDueGrace(): bool
    {
        $graceDays = (int) config('quotas.subscriptions.past_due_grace_days', 0);

        if ($graceDays < 1) {
            return false;
        }

        // Applications that never record when the payment failed still get a
        // window; updated_at is when the status was last written, which is the
        // closest thing to it.
        $since = $this->past_due_since ?? $this->updated_at;

        return $since !== null && $since->copy()->addDays($graceDays)->isFuture();
    }

    public function isCancelled(): bool
    {
        return $this->status->isCancelled();
    }

    public function isExpired(): bool
    {
        return $this->status === SubscriptionStatus::Expired || $this->hasEnded();
    }

    public function isOnGracePeriod(): bool
    {
        return $this->isCancelled() && $this->ends_at?->isFuture();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        $graceDays = (int) config('quotas.subscriptions.past_due_grace_days', 0);

        return $query
            ->where(function (Builder $q) use ($graceDays): void {
                $q->where('status', SubscriptionStatus::Active)
                    ->orWhere(function (Builder $trialing): void {
                        $trialing->where('status', SubscriptionStatus::Trialing)
                            ->where('trial_ends_at', '>', now());
                    })
                    // Cancelled but not yet over: the customer paid for this
                    // period and keeps it until it runs out.
                    ->orWhere(function (Builder $cancelled): void {
                        $cancelled->where('status', SubscriptionStatus::Cancelled)
                            ->whereNotNull('ends_at')
                            ->where('ends_at', '>', now());
                    });

                if ($graceDays > 0) {
                    $q->orWhere(function (Builder $pastDue) use ($graceDays): void {
                        $pastDue->where('status', SubscriptionStatus::PastDue)
                            ->where(
                                DB::raw('COALESCE(past_due_since, updated_at)'),
                                '>',
                                now()->subDays($graceDays)
                            );
                    });
                }
            })
            // Whatever the status claims, a term that has run out grants
            // nothing: an Active row with a past ends_at must not resolve
            // its plan.
            ->where(function (Builder $q): void {
                $q->whereNull('ends_at')->orWhere('ends_at', '>', now());
            });
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
