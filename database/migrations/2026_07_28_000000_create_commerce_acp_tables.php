<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->sessionsTable(), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('status')->index();
            $table->json('payload');
            $table->timestamps();
        });

        Schema::create($this->ordersTable(), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('checkout_session_id')->index();
            $table->string('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            // Opaque catalog refs only — the minimal order never learns what a ref is.
            $table->json('item_refs');
            $table->string('payment_id');
            $table->string('provider_ref')->nullable();
            $table->string('driver');
            // The receipt recorded against the order (buyer-facing proof of the charge).
            $table->string('receipt_id')->nullable();
            // The agent-provenance trace: which agent / session / reason drove the sale.
            $table->json('provenance')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->ordersTable());
        Schema::dropIfExists($this->sessionsTable());
    }

    private function sessionsTable(): string
    {
        return config('commerce.table_names.acp_checkout_sessions', 'commerce_acp_checkout_sessions');
    }

    private function ordersTable(): string
    {
        return config('commerce.table_names.acp_orders', 'commerce_acp_orders');
    }
};
