<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('quotas.table_names.plans', 'quota_plans'), function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('monthly_price')->nullable();
            $table->unsignedInteger('yearly_price')->nullable();
            $table->string('currency', 3)->default('USD');
            $table->json('features')->nullable();
            $table->json('limits')->nullable();

            // Maps this plan to the price identifiers of each billing provider,
            // e.g. {"stripe": ["price_123", "price_yearly"]}. This is the link
            // used to resolve a plan from an external subscription.
            $table->json('gateway_prices')->nullable();

            $table->json('metadata')->nullable();
            $table->unsignedInteger('trial_days')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('quotas.table_names.plans', 'quota_plans'));
    }
};
