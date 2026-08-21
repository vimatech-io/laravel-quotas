<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Enums;

use Carbon\CarbonInterface;

enum BillingInterval: string
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';
    case Lifetime = 'lifetime';

    /**
     * The end of a period starting at the given moment, or null for a
     * subscription that never renews.
     *
     * The no-overflow variants matter here for the same reason they matter to
     * quota periods: plain addMonths() turns a January 31st renewal into March
     * 3rd, billing the customer for a month they never had.
     */
    public function endFrom(CarbonInterface $start): ?CarbonInterface
    {
        return match ($this) {
            self::Monthly => $start->copy()->addMonthNoOverflow(),
            self::Yearly => $start->copy()->addYearNoOverflow(),
            self::Lifetime => null,
        };
    }
}
