<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Exceptions;

final class PlanNotFoundException extends QuotasException
{
    public static function withSlug(string $slug): self
    {
        return new self("Plan with slug [{$slug}] not found.");
    }

    public static function notSellable(string $slug): self
    {
        return new self(
            "Plan [{$slug}] is not available for subscription. "
            .'It either does not exist or has been deactivated.'
        );
    }

    public static function withId(int $id): self
    {
        return new self("Plan with ID [{$id}] not found.");
    }
}
