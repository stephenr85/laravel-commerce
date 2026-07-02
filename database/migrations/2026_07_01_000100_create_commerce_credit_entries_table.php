<?php

declare(strict_types=1);

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
            $table->decimal('amount', 20, 6);
            $table->uuid('purchase_id')->nullable();
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->index(['party_id', 'unit']);
        });
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
