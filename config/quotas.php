<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Subscription Resolution
    |--------------------------------------------------------------------------
    |
    | Where entitlements come from. This package does not charge anyone: it
    | reads whichever system owns the subscription and enforces the plan's
    | features and quotas against it.
    |
    |   "local"          : the subscriptions table shipped with this package,
    |                      for accounts granted by hand or without a provider.
    |                      The only option supporting several billable types.
    |   "cashier-stripe" : a laravel/cashier subscription.
    |   "cashier-paddle" : a laravel/cashier-paddle subscription.
    |
    | You may also name your own SubscriptionResolverInterface implementation.
    |
    | With a Cashier resolver, map each plan to its provider price ids through
    | the plan's `gateway_prices` column, e.g. ['stripe' => ['price_123']].
    |
    */
    'subscriptions' => [
        'resolver' => env('QUOTAS_RESOLVER', 'local'),

        /*
        | Which Cashier subscription to read, for applications that keep more
        | than one per customer. Ignored by the local resolver.
        */
        'cashier_type' => env('QUOTAS_CASHIER_TYPE', 'default'),

        /*
        | How long a past due local subscription keeps its entitlements while
        | you retry the payment. Zero cuts access the moment the status is set,
        | which is rarely what you want: most failed payments are recovered
        | within the first few days. Ignored by Cashier resolvers, where
        | Cashier's own valid() decides.
        */
        'past_due_grace_days' => env('QUOTAS_PAST_DUE_GRACE_DAYS', 0),

        /*
        | Slug of the plan a billable holds while it has no subscription, for a
        | free tier. Applies under every resolver, your own included. A billable
        | whose subscription maps to no plan does not get it. A slug that
        | matches no plan throws PlanNotFoundException. Null leaves billables
        | without a subscription planless.
        */
        'default_plan' => env('QUOTAS_DEFAULT_PLAN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Quotas
    |--------------------------------------------------------------------------
    |
    | How usage allowances roll over.
    |
    | Periods are measured from the subscription's anniversary, not from the
    | calendar: someone who subscribed on the 20th gets their allowance back on
    | the 20th. Without a subscription to anchor to, the calendar is used, in
    | the application timezone.
    |
    | Supported intervals: "daily", "weekly", "monthly", "yearly", "manual".
    | "manual" never rolls over on its own: you call resetUsage() yourself.
    |
    */
    'quotas' => [
        'reset_interval' => env('QUOTAS_RESET_INTERVAL', 'monthly'),

        /*
        | Features whose allowance follows its own interval instead of the
        | application-wide one above. Useful when one plan mixes cadences, such
        | as AI tokens that come back weekly next to exports that stay monthly:
        |
        |     'feature_intervals' => ['ai_tokens' => 'weekly'],
        |
        | A feature not named here follows reset_interval.
        */
        'feature_intervals' => [],

        /*
        | Features whose period follows the calendar even for subscribers, so
        | the allowance comes back on the 1st of the month (or the start of the
        | day, week or year) in the application timezone:
        |
        |     'feature_anchors' => ['invoice_issuing' => 'calendar'],
        |
        | A feature not named here is anchored to the subscription. An unknown
        | value throws.
        */
        'feature_anchors' => [],

        /*
        | Register the daily `quotas:reset` sweep on the scheduler.
        |
        | Counters also reset lazily the first time they are read in a new
        | period, so turning this off delays untouched counters rather than
        | letting quotas run stale.
        */
        'schedule_reset' => env('QUOTAS_SCHEDULE_RESET', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Currency
    |--------------------------------------------------------------------------
    |
    | Used when displaying plan prices. ISO 4217 codes.
    |
    */
    'currency' => env('QUOTAS_CURRENCY', 'USD'),

    /*
    |--------------------------------------------------------------------------
    | Trial Days
    |--------------------------------------------------------------------------
    |
    | Default trial length for subscriptions created through the local
    | resolver. Individual plans override this. Ignored by Cashier resolvers,
    | where trials are Cashier's business.
    |
    */
    'trial_days' => env('QUOTAS_TRIAL_DAYS', 0),

    /*
    |--------------------------------------------------------------------------
    | Middleware Responses
    |--------------------------------------------------------------------------
    |
    | What a gated route does when it turns someone away. The three refusals
    | are deliberately distinct: "log in", "upgrade" and "come back next
    | period" are three different screens, and two of them are a sale.
    |
    | Set `upgrade_route` to the name of your pricing page and browser requests
    | are redirected there instead of hitting an error page. API requests always
    | get the status code: 401 unauthenticated, 403 the plan does not grant the
    | feature, 402 the allowance is spent.
    |
    */
    'middleware' => [
        'upgrade_route' => env('QUOTAS_UPGRADE_ROUTE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Table Names
    |--------------------------------------------------------------------------
    */
    'table_names' => [
        'plans' => 'quota_plans',
        'subscriptions' => 'quota_subscriptions',
        'usages' => 'quota_usages',
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Settings
    |--------------------------------------------------------------------------
    |
    | Usage counters are read far more often than they change, so they are
    | cached briefly. A write invalidates the entry once its transaction
    | commits, nothing is cached from inside a transaction, and no entry
    | outlives the period it was counted in. The TTL only bounds how long a
    | counter changed outside this package can look stale.
    |
    */
    'cache' => [
        'enabled' => true,
        'prefix' => 'quotas',
        'ttl' => 60, // seconds
        'store' => null, // null = default cache store
    ],

];
