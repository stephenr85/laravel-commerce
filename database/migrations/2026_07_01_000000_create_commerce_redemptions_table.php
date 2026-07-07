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
            $table->string('code')->unique();
            $table->string('status')->index();
            $table->uuid('purchase_id');
            $table->string('payer_id');
            $table->string('beneficiary_party_id')->nullable();
            $table->string('deliver_to')->nullable();
            $table->string('redeemed_by')->nullable();
            $table->json('purchase_snapshot');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return config('commerce.table_names.redemptions', 'commerce_redemptions');
    }
};
