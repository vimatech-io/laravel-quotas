<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Exceptions;

use Illuminate\Database\Eloquent\Model;
use VimaTech\LaravelQuotas\Models\Subscription;

final class AlreadySubscribedException extends QuotasException
{
    public function __construct(
        string $message,
        public readonly ?Subscription $subscription = null,
    ) {
        parent::__construct($message);
    }

    public static function forBillable(Model $billable, ?Subscription $existing = null): self
    {
        $type = $billable->getMorphClass();
        $id = $billable->getKey();

        return new self(
            "[{$type}:{$id}] already has an active subscription. "
            .'Swap the plan instead of subscribing again.',
            $existing,
        );
    }
}
