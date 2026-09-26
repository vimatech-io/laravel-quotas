# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.3.0] - 2026-09-26

### Added

- `quotas.quotas.feature_anchors`: a feature set to `calendar` renews at the start of the calendar period (the 1st of the month for `monthly`) in the application timezone, even for billables whose subscription has another anniversary. Features not listed keep the subscription anniversary. An unknown value throws `InvalidArgumentException`.
- `quotas.subscriptions.default_plan` (`QUOTAS_DEFAULT_PLAN`): the slug of the plan a billable holds while it has no subscription, for a free tier. It applies under every resolver, custom ones included. `isSubscribed()` stays false for such a billable, and a billable whose subscription maps to no plan does not receive it, so a missing `gateway_prices` entry is not hidden behind the free tier. A slug matching no plan throws `PlanNotFoundException`. Unset, nothing changes.
- `QuotaManager::periodEndsAt(Model $billable, string $feature): ?CarbonImmutable`, the instant the allowance of a feature comes back for a billable. Null on the `manual` interval.

### Fixed

- A usage count read inside a database transaction was cached for every process. When the transaction rolled back, the cache kept reporting the uncommitted count until the TTL expired. Counts are no longer cached from inside a transaction, and invalidations wait for the commit, so another process cannot re-cache the old count in between.
- `UsageLimitReached` and `UsageReset` implement `ShouldDispatchAfterCommit`. An increment rolled back by an enclosing transaction no longer announces a limit that was never reached.
- A counter rolled over by `incrementUsage()` now dispatches `UsageReset`, as the read path already did.
- A cached count could outlive the period it was counted in by up to the cache TTL, so `canUse()` kept refusing for up to a minute after the allowance came back. Cached counts now expire at the end of their period.
- The subscription anchor memoised by the reset logic was not cleared by `forgetPlan()` or at the end of the request. A worker that had measured a period from one billing cycle kept measuring from it after the cycle moved.
- A custom resolver composing `CashierStripeResolver` or `CashierPaddleResolver` through the container received the `default` Cashier subscription type instead of `quotas.subscriptions.cashier_type`.
- `isSubscribedTo()` requires a subscription, so it does not report a billable on the default plan as subscribed to it.
- `IncrementUsageAction::execute()`, `incrementUsage()` and `EntitlementManager::increment()` declared only `UsageLimitExceededException`, so static analysis reported a `catch` of `PlanNotFoundException`, `BillableNotCashierReadyException` or `InvalidArgumentException` around them as dead code, although each can be thrown. Their `@throws` now list all four, and `currentPlan()`, `hasFeature()`, `canUse()`, `hasReachedLimit()`, `remaining()`, `remainingUsage()`, `isUnlimited()`, `hasUnlimited()` and `EnsureFeatureIsAvailable` declare the ones they can raise. The lists cover this package's exceptions: database errors and exceptions thrown by a custom resolver are not listed.
- `Plan::$monthly_price` was declared `int`, although the column is nullable and `PlanData` leaves it `null` by default. Static analysis therefore reported a `null` check on a plan without a monthly price as always true. It is now declared `int|null`, like `yearly_price`.

### Changed

- `QuotaManager` takes a `PlanManager` as a fourth constructor argument. Code resolving it from the container is unaffected.

## [1.2.0] - 2026-09-01

### Added

- `QuotaManager::flush()`, which drops every memoised entitlement answer at once.

### Fixed

- Memoised entitlements and the plan catalogue are cleared when the application terminates instead of relying on `scoped()` bindings being dropped between requests. Laravel clears scoped bindings in one place only — between queue jobs — so under a worker loop written without Octane the managers outlived the request that built them. A subscription cancelled between two requests was still reported as active, and a gateway price added after a worker had loaded the catalogue could not be resolved.

### Changed

- A test now pins the migrations to `publishesMigrations()`, which the package already used. It was the only package publishing migrations correctly, and nothing stopped that from being undone.
- `composer analyse` runs PHPStan with `--memory-limit=512M`, matching the other packages. Without it the analysis crashed against the default 128M limit rather than reporting anything.
- Added the shared project files the other packages carry: `CONTRIBUTING.md`, `SECURITY.md`, `.github/workflows/ci.yml` and `.github/dependabot.yml`. `LICENSE` is renamed `LICENSE.md` and its copyright line aligned; the MIT terms are unchanged.

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
