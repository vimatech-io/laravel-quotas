<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class UsageReset
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Model $billable,
        public readonly string $feature,
        /** What the counter stood at before it was cleared. */
        public readonly int $previousUsage,
    ) {}
}
