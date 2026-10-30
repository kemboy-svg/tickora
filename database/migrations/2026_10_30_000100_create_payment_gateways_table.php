<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payment provider credentials, managed by platform admins in the app.
 * Secret keys and webhook secrets are stored encrypted with the app key
 * (Laravel's "encrypted" cast) and are never sent back to the browser.
 *
 * Events can name the provider they take payments with; when they don't,
 * the provider marked as default is used.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateways', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20)->unique();
            $table->boolean('enabled')->default(false);
            $table->boolean('is_default')->default(false);
            $table->string('public_key')->nullable();
            $table->text('secret_key')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('events', function (Blueprint $table) {
            $table->string('payment_provider', 20)->nullable()->after('currency');
        });

        Schema::table('payments', function (Blueprint $table) {
            // The provider's hosted page, reused if the customer presses Pay again.
            $table->text('checkout_url')->nullable()->after('provider_status');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE payment_gateways ADD CONSTRAINT payment_gateways_provider_check CHECK (provider IN ('stripe','paystack'))");
            DB::statement('CREATE UNIQUE INDEX payment_gateways_one_default ON payment_gateways (is_default) WHERE is_default');
            DB::statement("ALTER TABLE events ADD CONSTRAINT events_payment_provider_check CHECK (payment_provider IS NULL OR payment_provider IN ('stripe','paystack','fake'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE events DROP CONSTRAINT IF EXISTS events_payment_provider_check');
        }

        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn('checkout_url'));
        Schema::table('events', fn (Blueprint $table) => $table->dropColumn('payment_provider'));
        Schema::dropIfExists('payment_gateways');
    }
};
