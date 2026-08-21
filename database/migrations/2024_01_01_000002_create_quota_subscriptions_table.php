<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('quotas.table_names.subscriptions', 'quota_subscriptions'), function (Blueprint $table) {
            $table->id();
            $table->morphs('billable');
            $table->foreignId('plan_id')
                ->constrained(config('quotas.table_names.plans', 'quota_plans'))
                ->cascadeOnDelete();
            $table->string('status')->default('active');

            // How often this subscription renews. Cancelling at period end is
            // meaningless without it: a yearly subscriber who cancels on day
            // one keeps the year they paid for, not a month.
            $table->string('interval')->default('monthly');
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();

            // When the payment first failed. Entitlements survive for
            // quotas.subscriptions.past_due_grace_days from this point.
            $table->timestamp('past_due_since')->nullable();

            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['billable_type', 'billable_id', 'status']);
            $table->index('status');
            $table->index('current_period_end');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('quotas.table_names.subscriptions', 'quota_subscriptions'));
    }
};
