<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Rushing\SchemaConvergence\ConvergentTable;

/**
 * Publish-only spatie/laravel-package-tools stub (timestamp-less `.php.stub`, re-stamped on
 * `vendor:publish --tag=commerce-migrations`). No squash: this was already a single standalone
 * create with no ALTER chain — only the timestamp prefix and `.php` extension changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        // CONVERGENT guard (rushing/laravel-schema-convergence/docs/agents/convergent-migration-guards.convention.md):
        // create when absent, top up when a copy already exists, throw when one exists with an
        // incompatible column type.
        ConvergentTable::named($this->table())
            ->define(function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('party_id')->index();
                $table->string('unit');
                $table->decimal('amount', 20, 6);
                $table->uuid('purchase_id')->nullable();
                $table->string('reason')->nullable();
                $table->timestamps();

                $table->index(['party_id', 'unit']);
            })
            ->assert();
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return config('commerce.table_names.credit_entries', 'commerce_credit_entries');
    }
};
