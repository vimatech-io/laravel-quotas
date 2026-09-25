<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Enums;

enum PeriodAnchor: string
{
    case Subscription = 'subscription';
    case Calendar = 'calendar';
}
