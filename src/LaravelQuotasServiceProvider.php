<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use VimaTech\LaravelQuotas\Console\ResetQuotasCommand;
use VimaTech\LaravelQuotas\Contracts\SubscriptionResolverInterface;
use VimaTech\LaravelQuotas\Managers\EntitlementManager;
use VimaTech\LaravelQuotas\Managers\PlanManager;
use VimaTech\LaravelQuotas\Managers\QuotaManager;
use VimaTech\LaravelQuotas\Managers\SubscriptionManager;
use VimaTech\LaravelQuotas\Quota\UsageCache;
use VimaTech\LaravelQuotas\Quota\UsageResetter;
use VimaTech\LaravelQuotas\Resolvers\CashierPaddleResolver;
use VimaTech\LaravelQuotas\Resolvers\CashierStripeResolver;
use VimaTech\LaravelQuotas\Resolvers\LocalSubscriptionResolver;

final class LaravelQuotasServiceProvider extends ServiceProvider
{
    /**
     * Resolvers shipped with the package, by config key.
     *
     * @var array<string, class-string<SubscriptionResolverInterface>>
     */
    private const RESOLVERS = [
        'local' => LocalSubscriptionResolver::class,
        'cashier-stripe' => CashierStripeResolver::class,
        'cashier-paddle' => CashierPaddleResolver::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/quotas.php', 'quotas');

        $this->registerResolver();
        $this->registerManagers();
    }

    public function boot(): void
    {
        $this->publishConfig();
        $this->publishMigrations();
        $this->forgetMemoisedAnswersOnTermination();

        if ($this->app->runningInConsole()) {
            $this->commands([ResetQuotasCommand::class]);
            $this->scheduleQuotaResets();
        }
    }

    /**
     * Scoped bindings are dropped by the runner, not by the framework: Laravel
     * itself only does it between queue jobs. A worker loop written without
     * Octane would keep these managers, and answer a later request with an
     * entitlement or a catalogue resolved during an earlier one. Registered
     * once for the application, never per resolved instance.
     */
    private function forgetMemoisedAnswersOnTermination(): void
    {
        $this->app->terminating(function (): void {
            $this->app->make(QuotaManager::class)->flush();
            $this->app->make(PlanManager::class)->flush();
        });
    }

    private function registerResolver(): void
    {
        $this->app->scoped(SubscriptionResolverInterface::class, function (Container $app): SubscriptionResolverInterface {
            $key = (string) config('quotas.subscriptions.resolver', 'local');

            // A custom resolver may be named by class instead of by key.
            $class = self::RESOLVERS[$key] ?? $key;

            if (! is_subclass_of($class, SubscriptionResolverInterface::class)) {
                $known = implode(', ', array_keys(self::RESOLVERS));

                throw new InvalidArgumentException(
                    "Unknown subscription resolver [{$key}]. Use one of [{$known}], "
                    .'or the class name of your own SubscriptionResolverInterface implementation.'
                );
            }

            if (is_a($class, CashierStripeResolver::class, true) || is_a($class, CashierPaddleResolver::class, true)) {
                return new $class(
                    $app->make(PlanManager::class),
                    (string) config('quotas.subscriptions.cashier_type', 'default'),
                );
            }

            return $app->make($class);
        });
    }

    private function registerManagers(): void
    {
        // Scoped bindings for Octane compatibility: these memoise per-request
        // answers (the current plan, the catalogue) and must not leak between
        // requests served by the same worker.
        $this->app->scoped(EntitlementManager::class);
        $this->app->scoped(PlanManager::class);
        $this->app->scoped(SubscriptionManager::class);
        $this->app->scoped(QuotaManager::class);
        $this->app->scoped(UsageResetter::class);
        $this->app->scoped(UsageCache::class);

        $this->app->alias(EntitlementManager::class, 'quotas');
    }

    /**
     * Sweep rolled-over quotas daily, unless the application opts out.
     *
     * Counters also reset lazily when they are read, so a missing scheduler
     * degrades timing rather than correctness.
     */
    private function scheduleQuotaResets(): void
    {
        if (! config('quotas.quotas.schedule_reset', true)) {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command(ResetQuotasCommand::class)
                ->daily()
                ->withoutOverlapping();
        });
    }

    private function publishConfig(): void
    {
        $this->publishes([
            __DIR__.'/../config/quotas.php' => config_path('quotas.php'),
        ], 'quotas-config');
    }

    private function publishMigrations(): void
    {
        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'quotas-migrations');
    }
}
