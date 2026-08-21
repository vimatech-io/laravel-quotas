<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\DTOs;

final readonly class PlanData
{
    /**
     * @param  array<int, string>  $features
     * @param  array<string, int>  $limits
     * @param  array<string, array<int, string>|string>  $gatewayPrices  provider price ids, e.g. ['stripe' => ['price_123']]
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $name,
        public string $slug,
        public ?string $description = null,
        public ?int $monthlyPrice = null,
        public ?int $yearlyPrice = null,
        public string $currency = 'USD',
        public array $features = [],
        public array $limits = [],
        public array $gatewayPrices = [],
        public array $metadata = [],
        public ?int $trialDays = null,
        public int $sortOrder = 0,
    ) {}
}
