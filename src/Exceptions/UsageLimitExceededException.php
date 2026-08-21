<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Exceptions;

final class UsageLimitExceededException extends QuotasException
{
    public static function forFeature(string $feature): self
    {
        return new self("Usage limit exceeded for feature [{$feature}].");
    }
}
