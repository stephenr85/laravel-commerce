<?php

namespace Rushing\Commerce\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Rushing\Commerce\CommerceServiceProvider;
use Spatie\LaravelData\LaravelDataServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            LaravelDataServiceProvider::class,
            CommerceServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('commerce.driver', 'fake');
    }

    /**
     * The package now ships publish-only .stub migrations (never auto-loaded at runtime —
     * see {@see CommerceServiceProvider::configurePackage()}), so the test harness builds its
     * own copy of the schema directly, mirroring exactly what each `database/migrations/*.stub`
     * file creates. Same pattern the beam family's own test harnesses use post-conversion
     * (`Splicewire\Beam\Accounts\Tests\TestCase::createTeamsSchema()` et al.).
     */
    protected function defineDatabaseMigrations(): void
    {
        Schema::create('commerce_redemptions', function (Blueprint $table): void {
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

        Schema::create('commerce_credit_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('party_id')->index();
            $table->string('unit');
            $table->decimal('amount', 20, 6);
            $table->uuid('purchase_id')->nullable();
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->index(['party_id', 'unit']);
        });

        Schema::create('commerce_auto_reload_configs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('party_id')->index();
            $table->string('unit');
            $table->boolean('enabled')->default(false);
            $table->decimal('threshold_usd', 20, 6);
            $table->string('amount_mode')->default('fixed');
            $table->decimal('reload_amount_usd', 20, 6)->nullable();
            $table->decimal('target_usd', 20, 6)->nullable();
            $table->integer('cooldown_seconds')->nullable();
            $table->integer('max_reloads_per_period')->nullable();
            $table->decimal('max_spend_per_period_usd', 20, 6)->nullable();
            $table->decimal('max_per_reload_usd', 20, 6)->nullable();
            $table->integer('period_days')->default(30);
            $table->integer('consecutive_failures')->default(0);
            $table->string('disabled_reason')->nullable();
            $table->string('payment_method_source')->nullable();
            $table->timestamps();

            $table->unique(['party_id', 'unit']);
        });

        Schema::create('commerce_auto_reload_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('party_id')->index();
            $table->string('unit');
            $table->string('reason')->index();
            $table->string('outcome');
            $table->string('stripe_error_code')->nullable();
            $table->decimal('amount_usd', 20, 6)->nullable();
            $table->string('provider_ref')->nullable();
            $table->integer('consecutive_failures_after')->default(0);
            $table->boolean('caused_disable')->default(false);
            $table->timestamp('created_at')->nullable();

            $table->index(['party_id', 'unit', 'created_at']);
        });

        Schema::create('commerce_acp_checkout_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('status')->index();
            $table->json('payload');
            $table->timestamps();
        });

        Schema::create('commerce_acp_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('checkout_session_id')->index();
            $table->string('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->json('item_refs');
            $table->string('payment_id');
            $table->string('provider_ref')->nullable();
            $table->string('driver');
            $table->string('receipt_id')->nullable();
            $table->json('provenance')->nullable();
            $table->timestamps();
        });
    }
}
