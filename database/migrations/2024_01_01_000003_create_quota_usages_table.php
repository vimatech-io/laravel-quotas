<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('quotas.table_names.usages', 'quota_usages'), function (Blueprint $table) {
            $table->id();
            $table->morphs('billable');

            // Counters belong to the billable, not to a subscription row: the
            // authoritative subscription may live in Cashier's tables, or in no
            // table at all. Plan changes rewrite the limit in place.
            $table->string('feature');
            $table->unsignedInteger('used')->default(0);
            $table->integer('limit')->default(-1);
            $table->timestamp('reset_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['billable_type', 'billable_id', 'feature']);
            $table->index('feature');

            // Scanned by quotas:reset to find counters whose period rolled over.
            $table->index('reset_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('quotas.table_names.usages', 'quota_usages'));
    }
};
