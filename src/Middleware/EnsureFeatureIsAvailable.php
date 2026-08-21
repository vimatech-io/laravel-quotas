<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use VimaTech\LaravelQuotas\Exceptions\FeatureNotAvailableException;
use VimaTech\LaravelQuotas\Managers\QuotaManager;

final class EnsureFeatureIsAvailable
{
    public function __construct(
        private readonly QuotaManager $quotaManager,
    ) {}

    /**
     * @throws AuthenticationException
     * @throws FeatureNotAvailableException
     */
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $billable = $request->user();

        // Not a 403: nothing is forbidden yet, we just do not know who this is.
        // Throwing Laravel's own exception gets the redirect-to-login for
        // browsers and the 401 for API clients, both for free.
        if ($billable === null) {
            throw new AuthenticationException;
        }

        // Asked in this order so the refusal says which of the two it is:
        // "buy a bigger plan" and "wait for the period to turn over" are
        // different answers, and collapsing them into one status would leave
        // the client unable to tell them apart.
        if (! $this->quotaManager->hasFeature($billable, $feature)) {
            throw FeatureNotAvailableException::notGranted($feature);
        }

        if ($this->quotaManager->hasReachedLimit($billable, $feature)) {
            throw FeatureNotAvailableException::limitReached($feature);
        }

        return $next($request);
    }
}
