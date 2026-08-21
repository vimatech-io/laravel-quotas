# Laravel Quotas

[![Tests](https://github.com/vimatech-io/laravel-quotas/actions/workflows/tests.yml/badge.svg)](https://github.com/vimatech-io/laravel-quotas/actions)
<!-- Restore these once the package is on Packagist:
[![Latest Version on Packagist](https://img.shields.io/packagist/v/vimatech/laravel-quotas.svg)](https://packagist.org/packages/vimatech/laravel-quotas)
[![License](https://img.shields.io/packagist/l/vimatech/laravel-quotas.svg)](https://packagist.org/packages/vimatech/laravel-quotas)
-->

Feature entitlements and usage quotas for Laravel SaaS applications.

**This package does not charge anyone.** Laravel Cashier already does that, and
does it well: checkout, payment methods, proration, dunning, invoices, the
Stripe billing portal. What Cashier does not answer is the question your
application asks on every request — *is this account allowed to do this, and has
it used up its allowance?*

That is the entire job here. You keep Cashier for the money; this package reads
whichever subscription Cashier holds, maps it to a plan, and enforces the plan's
features and quotas locally.

**And it is not Pennant.** Laravel Pennant decides whether a flag is on for a
user, and it is the right tool for that. It does not count. There is no usage
counter, no allowance, no billing period, no atomic ceiling. Pennant opens or
closes the door; this package counts how many times you went through it, and
closes the door when the plan says you are done.

## What you get

- **Feature gates** — `$user->hasFeature('api')`, plus route middleware.
- **Usage quotas** — counters enforced atomically under a row lock, so
  concurrent requests cannot both pass the check and overshoot the limit.
- **Automatic periods** — allowances roll over on the subscription's
  anniversary, lazily on read and through a scheduled sweep.
- **A plan catalogue** — features, limits and prices, mapped to your provider's
  price ids.
- **Any billable model** — usage is polymorphic, so `Team` and `Organization`
  work as well as `User`.
- **Cashier Stripe, Cashier Paddle, or neither** — one small interface decides
  where subscriptions come from.

## Requirements

- PHP 8.3+
- Laravel 12 or 13

## Installation

```bash
composer require vimatech/laravel-quotas
```

```bash
php artisan vendor:publish --tag=quotas-config
php artisan vendor:publish --tag=quotas-migrations
php artisan migrate
```

## Quick start

### 1. Add the trait to your billable model

```php
use VimaTech\LaravelQuotas\Traits\HasQuotas;

class User extends Authenticatable
{
    use HasQuotas;   // sits alongside Laravel\Cashier\Billable without colliding
}
```

### 2. Choose where subscriptions come from

```php
// config/quotas.php
'subscriptions' => [
    'resolver' => 'cashier-stripe',   // or 'cashier-paddle', or 'local'
],
```

| Resolver | Subscriptions live in | Use when |
|---|---|---|
| `cashier-stripe` | `laravel/cashier` | Stripe charges your customers |
| `cashier-paddle` | `laravel/cashier-paddle` | Paddle charges your customers |
| `local` | this package's own table | seats granted by hand, self-hosted licences, internal tenants, free tiers |

You may also point `resolver` at your own class implementing
`SubscriptionResolverInterface`.

### 3. Define plans

A plan is a catalogue entry: what it grants, how much of it, and which provider
prices it is sold under.

```php
use VimaTech\LaravelQuotas\DTOs\PlanData;
use VimaTech\LaravelQuotas\Managers\PlanManager;

app(PlanManager::class)->create(new PlanData(
    name: 'Pro',
    slug: 'pro',
    monthlyPrice: 2900,               // display only; the provider owns real pricing
    features: ['agents', 'api', 'webhooks'],
    limits: ['executions' => 1000, 'agents' => 10],
    gatewayPrices: ['stripe' => ['price_pro_monthly', 'price_pro_yearly']],
    trialDays: 14,
));
```

`gatewayPrices` is the link between Cashier and this package: monthly and yearly
prices for the same plan grant the same features. A limit of `-1` means
unlimited; a feature absent from `limits` is unlimited too.

### 4. Sell the plan through Cashier

Nothing in this package touches the checkout — that stays Cashier's:

```php
return $user->newSubscription('default', 'price_pro_monthly')
    ->trialDays(14)
    ->checkout([
        'success_url' => route('billing.success'),
        'cancel_url' => route('billing.cancel'),
    ]);
```

### 5. Gate features and count usage

```php
$user->hasFeature('api');            // does the plan grant it at all?
$user->canUse('executions');         // granted, and allowance not spent?
$user->hasReachedLimit('executions');

$user->incrementUsage('executions');       // throws if it would exceed the limit
$user->incrementUsage('executions', 5);

$user->usageOf('executions');        // 42
$user->remainingUsage('executions'); // 958, or null when unlimited
$user->hasUnlimited('executions');   // bool

$user->currentPlan();                // Plan|null, whoever holds the subscription
$user->isSubscribed();
$user->onTrial();
```

`incrementUsage()` is the one that enforces. It re-reads the counter under a row
lock, so two concurrent requests on the last unit cannot both succeed:

```php
public function store(Request $request)
{
    $request->user()->incrementUsage('executions');   // UsageLimitExceededException

    // ...
}
```

### 6. Protect routes

```php
use VimaTech\LaravelQuotas\Middleware\EnsureFeatureIsAvailable;
use VimaTech\LaravelQuotas\Middleware\EnsureSubscriptionIsActive;

Route::middleware(EnsureSubscriptionIsActive::class)->group(function () {
    Route::middleware(EnsureFeatureIsAvailable::class.':agents')->group(function () {
        // granted the feature, and allowance not spent
    });
});
```

Both middlewares share one memoised plan lookup per request, so stacking them
costs a single subscription resolution.

They distinguish three refusals, because they are three different screens and
two of them are a sale:

| Situation | API | Browser |
|---|---|---|
| Not authenticated | `401` | redirect to login |
| Plan does not grant the feature | `403` `feature_not_in_plan` | redirect to `upgrade_route` |
| Allowance spent | `402` `limit_reached` | redirect to `upgrade_route` |

Set `quotas.middleware.upgrade_route` to your pricing route and a turned-away
browser lands there with a `quotas-error` flash message. Leave it null and the
status code stands on its own.

## How quotas reset

Allowances are measured from the subscription's anniversary, not from the
calendar. Subscribe on the 20th and the allowance returns on the 20th — not on
the 1st because the month happened to turn over. An anniversary on the 31st
clamps to the last day of shorter months, the same rule providers apply.

Resets happen two ways:

- **Lazily**, the first time a counter is read or incremented in a new period.
  This is what makes correctness independent of your scheduler.
- **Through a sweep**, so counters nobody touches also return to zero and
  dashboards read correctly:

```bash
php artisan quotas:reset
```

The command is registered on the scheduler daily. Turn that off with
`quotas.quotas.schedule_reset` if you would rather run it yourself.

```php
'quotas' => [
    'reset_interval' => 'monthly',   // daily, weekly, monthly, yearly, manual
    'schedule_reset' => true,

    // Features that follow their own cadence instead of the default —
    // AI tokens back every week next to exports that stay monthly:
    'feature_intervals' => ['ai_tokens' => 'weekly'],
],
```

Periods are measured from `current_period_start` — the date the customer is
actually billed on — falling back to when the subscription was created. That
distinction matters after a plan change: providers reset the billing cycle on
proration, and anchoring to the creation date would drift the quota reset away
from the invoice date permanently.

`manual` never rolls over on its own — you call `$user->resetUsage('feature')`.

## Standing limits: counting things that exist, not things consumed

Not every number in a pricing grid is a consumable. "2 000 AI tokens a month"
is one — it spends down and comes back. "3 projects" is not: deleting a project
must free a slot immediately, and no period should ever refill it.

Usage counters only go up and reset by period, so they are the wrong tool for
standing limits. For those, the plan carries the ceiling and your own tables
carry the count:

```php
$limit = $user->currentPlan()?->getLimit('projects') ?? 0;

if ($limit !== Usage::UNLIMITED && $user->projects()->count() >= $limit) {
    throw FeatureNotAvailableException::limitReached('projects');
}
```

Deletion needs no quota code at all — the count drops, the slot is free.

## Without a payment provider

With `resolver => 'local'`, this package stores subscriptions itself. Useful for
manually granted seats, internal tenants and free tiers, and the only resolver
supporting several billable types at once:

```php
use VimaTech\LaravelQuotas\Enums\BillingInterval;

$user->subscribe('pro');                                  // monthly
$user->subscribe('pro', BillingInterval::Yearly);         // yearly
$user->swapPlan('enterprise');
$user->cancelSubscription();              // at the end of the period paid for
$user->cancelSubscription(immediately: true);
$user->resumeSubscription();
$user->currentSubscription();             // Subscription|null
```

The interval is what "cancel at period end" measures against: a yearly
subscriber who cancels on day one keeps the year they were charged for.

Two refusals are deliberate. `subscribe()` throws
`AlreadySubscribedException` when an active subscription already exists — two
of them for one billable is not a state this package can reason about, and a
double-clicked form is enough to produce it. And both `subscribe()` and
`swapPlan()` throw `PlanNotFoundException` for a deactivated plan: a plan you
have taken off the catalogue must not stay purchasable by slug. Plans already
subscribed to keep resolving after they are retired, so nobody loses what they
bought.

These write to the local table, so they throw
`LocalSubscriptionsDisabledException` under a Cashier resolver — where creating
and cancelling subscriptions is Cashier's job, not this package's. That refusal
is deliberate: a local "subscription" nobody is paying for is exactly the bug
this design exists to prevent.

## Events

| Event | Dispatched when |
|---|---|
| `UsageLimitReached` | a counter reaches its limit |
| `UsageReset` | a counter rolls over into a new period |
| `SubscriptionCreated` | a local subscription is created |
| `SubscriptionCancelled` | a local subscription is cancelled |
| `PlanChanged` | a local subscription's plan is swapped |

The last three fire for the `local` resolver only. Under Cashier, listen to
Cashier's own webhook events.

## Type-hinting a billable

Two interfaces describe what the trait adds, so your own code can be explicit
about which half it needs:

```php
use VimaTech\LaravelQuotas\Contracts\ManagesLocalSubscription;
use VimaTech\LaravelQuotas\Contracts\QuotaAware;

class User extends Authenticatable implements QuotaAware, ManagesLocalSubscription
{
    use HasQuotas;
}
```

`QuotaAware` is the read side — plan, features, usage — and behaves the same
whoever owns the subscription. `ManagesLocalSubscription` is the write side, and
throws under a Cashier resolver. Implement whichever you actually use.

## Writing your own resolver

Four methods, no payment logic:

```php
use VimaTech\LaravelQuotas\Contracts\SubscriptionResolverInterface;

final class LicenceServerResolver implements SubscriptionResolverInterface
{
    public function resolvePlan(Model $billable): ?Plan { /* ... */ }
    public function isSubscribed(Model $billable): bool { /* ... */ }
    public function onTrial(Model $billable): bool { /* ... */ }
    public function anchor(Model $billable): ?CarbonImmutable { /* ... */ }
}
```

```php
'subscriptions' => ['resolver' => LicenceServerResolver::class],
```

`anchor()` returns the date quota periods are measured from. Derive it from data
you already hold — it is consulted on ordinary requests and must never make a
network call.

## Configuration

See `config/quotas.php`. The keys that matter most:

```php
'subscriptions' => [
    'resolver' => env('QUOTAS_RESOLVER', 'local'),
    'cashier_type' => env('QUOTAS_CASHIER_TYPE', 'default'),
],

'quotas' => [
    'reset_interval' => env('QUOTAS_RESET_INTERVAL', 'monthly'),
    'schedule_reset' => env('QUOTAS_SCHEDULE_RESET', true),
],

'middleware' => [
    // Name your pricing route and a refused browser request lands there
    // instead of on an error page. API requests always get the status code.
    'upgrade_route' => env('QUOTAS_UPGRADE_ROUTE'),
],

'cache' => [
    'enabled' => true,
    'ttl' => 60,   // usage counters are read far more often than they change
],
```

Writes invalidate a cached counter immediately. The TTL only bounds how long a
counter changed *outside* this package can look stale.

## Testing

```bash
composer test        # Pest
composer analyse     # PHPStan level 6
composer format      # Pint
```

The Cashier resolvers are covered by fixtures reproducing Cashier's surface, so
the suite runs without Cashier installed.

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.

## Credits

Built and maintained by [Vimatech](https://vimatech.io).
Created by [Adel Zemzemi](https://github.com/adelzemzemi).
