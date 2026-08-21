<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Enums;

enum SubscriptionStatus: string
{
    case Active = 'active';
    case Trialing = 'trialing';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case PastDue = 'past_due';

    public function isActive(): bool
    {
        return $this === self::Active || $this === self::Trialing;
    }

    public function isCancelled(): bool
    {
        return $this === self::Cancelled;
    }

    public function isExpired(): bool
    {
        return $this === self::Expired;
    }
}
