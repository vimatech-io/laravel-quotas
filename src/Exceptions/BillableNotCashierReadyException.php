<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Exceptions;

use Illuminate\Database\Eloquent\Model;

final class BillableNotCashierReadyException extends QuotasException
{
    public static function for(Model $billable, string $gateway): self
    {
        $type = $billable->getMorphClass();
        $trait = $gateway === 'paddle'
            ? 'Laravel\Paddle\Billable'
            : 'Laravel\Cashier\Billable';

        return new self(
            "[{$type}] cannot resolve a {$gateway} subscription because it has no subscription() method. "
            ."Add the [{$trait}] trait to it, or switch quotas.subscriptions.resolver to \"local\"."
        );
    }
}
