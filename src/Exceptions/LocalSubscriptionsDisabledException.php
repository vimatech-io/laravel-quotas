<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Exceptions;

final class LocalSubscriptionsDisabledException extends QuotasException
{
    public static function forMethod(string $method): self
    {
        return new self(
            "[{$method}] writes to this package's own subscriptions table, but entitlements are currently "
            .'resolved from an external provider. Create, change and cancel subscriptions through that '
            .'provider instead — this package only reads the result and enforces quotas against it.'
        );
    }
}
