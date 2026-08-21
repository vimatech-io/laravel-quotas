<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use VimaTech\LaravelQuotas\Models\Subscription;

final class SubscriptionCreated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Subscription $subscription,
        public readonly Model $billable,
    ) {}
}
