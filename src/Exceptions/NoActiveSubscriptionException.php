<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Exceptions;

use Illuminate\Database\Eloquent\Model;

final class NoActiveSubscriptionException extends QuotasException
{
    public static function forBillable(Model $billable): self
    {
        $type = $billable->getMorphClass();
        $id = $billable->getKey();

        return new self("No active subscription found for [{$type}:{$id}].");
    }
}
