<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Exceptions;

use Illuminate\Http\Response;

/**
 * A gated route reached without any active subscription behind it.
 *
 * Distinct from a feature refusal: there is nothing to upgrade from, the
 * customer simply has no plan yet.
 */
final class SubscriptionRequiredException extends FeatureNotAvailableException
{
    public static function make(): self
    {
        return new self(
            Response::HTTP_PAYMENT_REQUIRED,
            '',
            'no_subscription',
            'An active subscription is required.',
        );
    }
}
