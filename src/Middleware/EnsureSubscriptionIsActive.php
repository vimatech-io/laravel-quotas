<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use VimaTech\LaravelQuotas\Exceptions\SubscriptionRequiredException;
use VimaTech\LaravelQuotas\Managers\QuotaManager;

final class EnsureSubscriptionIsActive
{
    public function __construct(
        private readonly QuotaManager $quotaManager,
    ) {}

    /**
     * @throws AuthenticationException
     * @throws SubscriptionRequiredException
     */
    public function handle(Request $request, Closure $next): Response
    {
        $billable = $request->user();

        if ($billable === null) {
            throw new AuthenticationException;
        }

        if (! $this->quotaManager->isSubscribed($billable)) {
            throw SubscriptionRequiredException::make();
        }

        return $next($request);
    }
}
