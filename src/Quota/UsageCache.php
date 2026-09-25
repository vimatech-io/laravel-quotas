<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Quota;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use VimaTech\LaravelQuotas\Models\Usage;

/**
 * Owns the cached usage counters.
 *
 * The cache is shared by every process, so it only ever holds committed
 * counts: nothing is cached from inside a transaction, and an invalidation
 * waits for the commit that makes the new count visible to other connections.
 */
final class UsageCache
{
    public function key(Model $billable, string $feature): string
    {
        $prefix = (string) config('quotas.cache.prefix', 'quotas');

        return "{$prefix}:usage:{$billable->getMorphClass()}:{$billable->getKey()}:{$feature}";
    }

    /**
     * @param  Closure(): int  $callback
     * @param  CarbonImmutable|null  $periodEnd  a count never outlives the period it was taken in
     */
    public function remember(Model $billable, string $feature, Closure $callback, ?CarbonImmutable $periodEnd = null): int
    {
        if (! config('quotas.cache.enabled', true) || $this->connection()->transactionLevel() > 0) {
            return $callback();
        }

        $expiresAt = CarbonImmutable::now()->addSeconds((int) config('quotas.cache.ttl', 60));

        if ($periodEnd !== null && $periodEnd->lessThan($expiresAt)) {
            $expiresAt = $periodEnd;
        }

        return (int) $this->store()->remember($this->key($billable, $feature), $expiresAt, $callback);
    }

    public function forget(Model $billable, string $feature): void
    {
        $key = $this->key($billable, $feature);

        $this->connection()->afterCommit(fn () => $this->store()->forget($key));
    }

    private function connection(): Connection
    {
        return (new Usage)->getConnection();
    }

    private function store(): Repository
    {
        return Cache::store(config('quotas.cache.store'));
    }
}
