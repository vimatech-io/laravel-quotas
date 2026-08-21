<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use VimaTech\LaravelQuotas\Events\UsageLimitReached;
use VimaTech\LaravelQuotas\Exceptions\UsageLimitExceededException;
use VimaTech\LaravelQuotas\Managers\QuotaManager;
use VimaTech\LaravelQuotas\Models\Usage;
use VimaTech\LaravelQuotas\Quota\UsageCache;
use VimaTech\LaravelQuotas\Quota\UsageResetter;

final class IncrementUsageAction
{
    public function __construct(
        private readonly QuotaManager $quotaManager,
        private readonly UsageResetter $resetter,
        private readonly UsageCache $cache,
    ) {}

    /**
     * Consume quota for a feature.
     *
     * The ceiling is enforced against the locked database row rather than the
     * cached counter, so concurrent requests cannot both pass the check and
     * push usage past the plan limit.
     *
     * @throws UsageLimitExceededException
     */
    public function execute(Model $billable, string $feature, int $amount = 1): void
    {
        if ($amount < 1) {
            throw new InvalidArgumentException(
                "Usage increment amount must be at least 1, [{$amount}] given."
            );
        }

        $plan = $this->quotaManager->currentPlan($billable);

        if ($plan === null || ! $plan->hasFeature($feature)) {
            throw UsageLimitExceededException::forFeature($feature);
        }

        // A feature with no entry in the plan's limits is unlimited.
        $limit = $plan->getLimit($feature) ?? Usage::UNLIMITED;

        $this->ensureUsageRecordExists($billable, $feature, $limit);

        $usage = DB::transaction(function () use ($billable, $feature, $amount, $limit) {
            $usage = Usage::query()
                ->forBillable($billable)
                ->forFeature($feature)
                ->lockForUpdate()
                ->first()
                ?? Usage::query()->create($this->defaultAttributes($billable, $feature, $limit));

            // The plan is the source of truth. A stale row must never grant
            // more (or less) than what the subscribed plan actually allows.
            $usage->limit = $limit;

            // Roll the period over inside the lock, so a request landing on the
            // boundary spends the new allowance rather than the old one.
            if ($this->resetter->hasRolledOver($billable, $usage)) {
                $usage->used = 0;
                $usage->reset_at = now();
            }

            if ($limit !== Usage::UNLIMITED && $usage->used + $amount > $limit) {
                throw UsageLimitExceededException::forFeature($feature);
            }

            $usage->used += $amount;
            $usage->save();

            return $usage;
        });

        $this->cache->forget($billable, $feature);

        if ($usage->hasReachedLimit()) {
            event(new UsageLimitReached($billable, $feature, $usage->used, $usage->limit));
        }
    }

    /**
     * Create the usage row if it is missing, tolerating a concurrent creation.
     *
     * Relies on the unique index over (billable_type, billable_id, feature) and
     * runs outside the transaction so a conflict never poisons it.
     */
    private function ensureUsageRecordExists(Model $billable, string $feature, int $limit): void
    {
        $exists = Usage::query()
            ->forBillable($billable)
            ->forFeature($feature)
            ->exists();

        if ($exists) {
            return;
        }

        $now = now();

        Usage::query()->insertOrIgnore([
            ...$this->defaultAttributes($billable, $feature, $limit),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultAttributes(Model $billable, string $feature, int $limit): array
    {
        return [
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => $billable->getKey(),
            'feature' => $feature,
            'used' => 0,
            'limit' => $limit,
            'reset_at' => now(),
        ];
    }
}
