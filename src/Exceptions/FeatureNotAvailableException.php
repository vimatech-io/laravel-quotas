<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Exceptions;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A gated route turning someone away.
 *
 * Two refusals wear this exception and they are not the same event: the plan
 * does not grant the feature at all (sell them a bigger plan), or the
 * allowance for this period is spent (sell them an add-on, or tell them when
 * it comes back). They carry different status codes so an API client can tell
 * them apart, and both redirect a browser to the pricing page when one is
 * configured, because an error page is a poor place to end a purchase.
 */
class FeatureNotAvailableException extends HttpException
{
    final public function __construct(
        int $statusCode,
        public readonly string $feature,
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($statusCode, $message);
    }

    /**
     * The current plan does not include this feature.
     */
    public static function notGranted(string $feature): static
    {
        return new static(
            Response::HTTP_FORBIDDEN,
            $feature,
            'feature_not_in_plan',
            'Your plan does not include this feature.',
        );
    }

    /**
     * The plan includes the feature, but the allowance is spent.
     */
    public static function limitReached(string $feature): static
    {
        return new static(
            Response::HTTP_PAYMENT_REQUIRED,
            $feature,
            'limit_reached',
            'You have used your allowance for this feature.',
        );
    }

    /**
     * Send an API client the machine-readable reason, and a browser to the
     * page where it can do something about it.
     */
    public function render(Request $request): mixed
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $this->getMessage(),
                'reason' => $this->reason,
                'feature' => $this->feature,
            ], $this->getStatusCode());
        }

        $route = config('quotas.middleware.upgrade_route');

        if (is_string($route) && $route !== '' && app('router')->has($route)) {
            return redirect()->route($route)->with('quotas-error', $this->getMessage());
        }

        return false;
    }
}
