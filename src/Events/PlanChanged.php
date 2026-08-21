<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use VimaTech\LaravelQuotas\Models\Plan;
use VimaTech\LaravelQuotas\Models\Subscription;

final class PlanChanged
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Subscription $subscription,
        public readonly Plan $oldPlan,
        public readonly Plan $newPlan,
    ) {}
}
