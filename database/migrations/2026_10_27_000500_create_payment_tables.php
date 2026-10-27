<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('provider', 40);
            // Connected / sub-merchant account at the provider, for marketplace splits.
            $table->string('provider_account_id')->nullable();
            $table->string('provider_payment_id')->nullable();
            // The provider's own status string, kept alongside our mapped status.
            $table->string('provider_status', 60)->nullable();
            $table->string('idempotency_key')->unique();
            $table->char('currency', 3);
            $table->bigInteger('amount_minor');
            $table->bigInteger('refunded_minor')->default(0);
            $table->string('status', 24)->default('created');
            $table->timestampTz('authorized_at')->nullable();
            $table->timestampTz('captured_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->string('failure_code', 80)->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_payment_id']);
            $table->index(['order_id', 'status']);
        });

        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 40);
            $table->string('provider_event_id');
            $table->string('event_type', 80);
            $table->boolean('signature_valid');
            $table->string('processing_status', 20)->default('received');
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->jsonb('payload')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('received_at');
            $table->timestampTz('processed_at')->nullable();
            $table->text('error_summary')->nullable();
            $table->timestamps();

            // Each provider event is processed at most once.
            $table->unique(['provider', 'provider_event_id']);
            $table->index(['processing_status', 'received_at']);
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('provider_refund_id')->nullable();
            $table->string('idempotency_key')->unique();
            $table->char('currency', 3);
            $table->bigInteger('amount_minor');
            $table->text('reason')->nullable();
            $table->string('status', 20)->default('requested');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->unique(['payment_id', 'provider_refund_id']);
            $table->index(['status', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status_check CHECK (status IN ('created','requires_action','processing','succeeded','failed','partially_refunded','refunded'))");
            DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_check CHECK (amount_minor > 0 AND refunded_minor >= 0 AND refunded_minor <= amount_minor)');

            DB::statement("ALTER TABLE payment_webhook_events ADD CONSTRAINT payment_webhook_events_status_check CHECK (processing_status IN ('received','processed','ignored','failed'))");

            DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_status_check CHECK (status IN ('requested','approved','rejected','processing','succeeded','failed'))");
            DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_amount_check CHECK (amount_minor > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('payment_webhook_events');
        Schema::dropIfExists('payments');
    }
};
