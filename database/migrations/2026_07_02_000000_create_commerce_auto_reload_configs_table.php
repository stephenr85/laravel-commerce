<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('party_id')->index();
            $table->string('unit');

            // Tenant intent (deliberate on/off); a failed/degraded config keeps enabled=true
            // so intent survives a vanished card — status is derived, never a stored enum.
            $table->boolean('enabled')->default(false);

            // Reload when balance() <= threshold_usd (an absolute floor, stable across top-ups).
            $table->decimal('threshold_usd', 20, 6);

            // fixed -> charge reload_amount_usd; to_target -> charge target_usd - balance().
            $table->string('amount_mode')->default('fixed');
            $table->decimal('reload_amount_usd', 20, 6)->nullable();
            $table->decimal('target_usd', 20, 6)->nullable();

            // Guardrails — tenant-tunable within engine safety clamps (effective = clamp(config, policy)).
            $table->integer('cooldown_seconds')->nullable();
            $table->integer('max_reloads_per_period')->nullable();
            $table->decimal('max_spend_per_period_usd', 20, 6)->nullable();
            $table->decimal('max_per_reload_usd', 20, 6)->nullable();
            $table->integer('period_days')->default(30);

            // Failure lifecycle (bumped/reset engine-side; feeds auto-disable-after-N).
            $table->integer('consecutive_failures')->default(0);
            $table->string('disabled_reason')->nullable();

            // The payment-method dependency only — never the pm_.../cus_... ref itself (ADR-0131).
            $table->string('payment_method_source')->nullable();

            $table->timestamps();

            $table->unique(['party_id', 'unit']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return config('commerce.table_names.auto_reload_configs', 'commerce_auto_reload_configs');
    }
};
