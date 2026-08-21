<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Quota;

use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Owns the cached usage counters.
 *
 * Kept apart from QuotaManager so that whatever writes to a counter can
 * invalidate it without depending on the manager that reads it.
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
     */
    public function remember(Model $billable, string $feature, Closure $callback): int
    {
        if (! config('quotas.cache.enabled', true)) {
            return $callback();
        }

        $ttl = (int) config('quotas.cache.ttl', 60);

        return (int) $this->store()->remember($this->key($billable, $feature), $ttl, $callback);
    }

    public function forget(Model $billable, string $feature): void
    {
        $this->store()->forget($this->key($billable, $feature));
    }

    private function store(): Repository
    {
        return Cache::store(config('quotas.cache.store'));
    }
}
