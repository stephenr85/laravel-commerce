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

            // autoreload:{party}:{unit}:{window} — the guardrail-scan key; ties an attempt
            // to its CreditEntry on success and is the dedup key across the four layers.
            $table->string('reason')->index();

            // succeeded | declined | sca_required | transient_error | blocked
            $table->string('outcome');
            $table->string('stripe_error_code')->nullable();
            $table->decimal('amount_usd', 20, 6)->nullable();
            $table->string('provider_ref')->nullable();

            // Counter snapshot at this attempt + whether this attempt tripped auto-disable —
            // the durable audit behind the config's cheap consecutive_failures summary.
            $table->integer('consecutive_failures_after')->default(0);
            $table->boolean('caused_disable')->default(false);

            $table->timestamp('created_at')->nullable();

            $table->index(['party_id', 'unit', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return config('commerce.table_names.auto_reload_attempts', 'commerce_auto_reload_attempts');
    }
};
