<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class UsageLimitReached implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Model $billable,
        public readonly string $feature,
        public readonly int $currentUsage,
        public readonly int $limit,
    ) {}
}
