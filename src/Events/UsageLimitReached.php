<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class UsageLimitReached
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
