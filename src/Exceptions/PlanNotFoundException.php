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

    public static function defaultPlan(string $slug): self
    {
        return new self(
            "The default plan [{$slug}] set in quotas.subscriptions.default_plan does not exist. "
            .'Create a plan with that slug, or set the option to null to leave billables without a subscription planless.'
        );
    }

    public static function withId(int $id): self
    {
        return new self("Plan with ID [{$id}] not found.");
    }
}
