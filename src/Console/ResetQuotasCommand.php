<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Console;

use Illuminate\Console\Command;
use VimaTech\LaravelQuotas\Quota\UsageResetter;

final class ResetQuotasCommand extends Command
{
    protected $signature = 'quotas:reset {--chunk=500 : How many usage rows to load at a time}';

    protected $description = 'Reset usage counters whose billing period has rolled over';

    public function handle(UsageResetter $resetter): int
    {
        $chunk = max(1, (int) $this->option('chunk'));

        $reset = $resetter->sweep($chunk);

        $this->info($reset === 0
            ? 'No usage counters needed resetting.'
            : "Reset {$reset} usage counter(s).");

        return self::SUCCESS;
    }
}
