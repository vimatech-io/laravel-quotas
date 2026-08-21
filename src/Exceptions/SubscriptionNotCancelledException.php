<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Exceptions;

use Illuminate\Database\Eloquent\Model;
use VimaTech\LaravelQuotas\Models\Subscription;

final class SubscriptionNotCancelledException extends QuotasException
{
    public static function forSubscription(Subscription $subscription): self
    {
        return new self("Subscription [{$subscription->id}] is not in a cancelled state.");
    }

    public static function noneToResume(Model $billable): self
    {
        $type = $billable->getMorphClass();
        $id = $billable->getKey();

        return new self("[{$type}:{$id}] has no cancelled subscription to resume.");
    }
}
