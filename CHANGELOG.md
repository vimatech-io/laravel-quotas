# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-08-21

### Added

- `LocalSubscriptionSource`, a marker interface a custom resolver declares when
  the local subscriptions table is one of its sources. Local writes —
  `subscribe()`, `swapPlan()`, `cancelSubscription()` — are authorised by the
  marker rather than by the shipped local resolver's concrete class, so a
  composite resolver (Paddle plus AppSumo-lifetime redemptions, Stripe plus
  manually granted tenants) can serve two subscription sources in one
  application. Covered by an end-to-end scenario test.

## [1.0.0] - 2026-08-21

First release. Feature entitlements and usage quotas for Laravel SaaS
applications — this package does not charge anyone; it reads whichever system
owns the subscription and enforces the plan's features and quotas against it.

### Entitlements

- `SubscriptionResolverInterface` decides where entitlements come from, with
  three shipped implementations — `local`, `cashier-stripe`, `cashier-paddle` —
  plus support for naming your own. Cashier is read through duck typing and is
  never a hard dependency.
- A plan catalogue (`quota_plans`) carrying features, limits, prices and a
  `gateway_prices` map, so a monthly and a yearly provider price resolve to the
  same plan.
- `HasQuotas` trait for any Eloquent model — billables are polymorphic, several
  types can coexist. Two contracts split its surface: `QuotaAware` (read side,
  identical under every resolver) and `ManagesLocalSubscription` (write side,
  local resolver only).
- A `Quotas` facade with the same surface: `plans`, `plan`, `currentPlan`,
  `subscribe`, `swap`, `cancel`, `resume`, `canUse`, `increment`, `remaining`.

### Usage quotas

- Counters enforced atomically against the locked database row, never against
  a cached value — concurrent requests cannot pass the check together and
  overshoot a limit.
- Quota periods measured from the subscription's billing anniversary, with
  no-overflow clamping for anniversaries that land past the end of a shorter
  month. Intervals: `daily`, `weekly`, `monthly`, `yearly`, `manual`; an
  unknown value throws rather than being silently reinterpreted.
- Per-feature reset intervals (`quotas.quotas.feature_intervals`), so one
  feature can come back weekly while the rest stay monthly.
- Resets happen lazily on every read and increment — correctness never depends
  on the scheduler — plus a daily `quotas:reset` sweep so untouched counters
  and dashboards stay fresh.
- Short-lived counter cache with immediate invalidation on write, and a
  configurable prefix for applications sharing a cache store.

### Local subscriptions

- A polymorphic subscriptions table for accounts nobody charges through a
  provider: manually granted seats, internal tenants, free tiers. The only
  resolver supporting several billable types at once.
- Billing intervals (`monthly`, `yearly`, `lifetime`) recorded per
  subscription, with `current_period_start` / `current_period_end`. Cancelling
  at period end keeps whatever term the customer paid for.
- Duplicate subscriptions are refused (`AlreadySubscribedException`), and
  deactivated plans cannot be subscribed to while remaining resolvable for
  grandfathered subscribers.
- A configurable grace window for failed payments
  (`quotas.subscriptions.past_due_grace_days`).
- Writes to the local table throw `LocalSubscriptionsDisabledException` under a
  Cashier resolver, where creating and cancelling subscriptions is Cashier's
  job — a local subscription nobody pays for is exactly the failure mode this
  package exists to prevent.

### Route protection

- `EnsureSubscriptionIsActive` and `EnsureFeatureIsAvailable:feature`
  middlewares, sharing one memoised subscription resolution per request.
- Three distinct refusals: `401` unauthenticated, `403` when the plan does not
  grant the feature, `402` when the allowance is spent — with an optional
  browser redirect to `quotas.middleware.upgrade_route`.

### Events and exceptions

- `UsageLimitReached`, `UsageReset`, `SubscriptionCreated`,
  `SubscriptionCancelled`, `PlanChanged`.
- Every exception extends `QuotasException`, so one catch handles anything
  quota-related.
