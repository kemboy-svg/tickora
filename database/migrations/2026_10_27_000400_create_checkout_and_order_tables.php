<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkouts', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('event_id')->constrained()->restrictOnDelete();
            $table->foreignId('promo_code_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_email');
            $table->char('currency', 3);
            $table->bigInteger('subtotal_minor')->default(0);
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('fees_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('total_minor')->default(0);
            $table->string('status', 20)->default('open');
            $table->timestampTz('expires_at');
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
        });

        Schema::create('inventory_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checkout_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_type_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('status', 20)->default('active');
            $table->timestampTz('expires_at');
            $table->timestamps();

            $table->index(['status', 'expires_at']);
            $table->index(['ticket_type_id', 'status']);
            $table->unique(['checkout_id', 'ticket_type_id']);
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            // Customer-facing reference, e.g. TKR-7Q4M-2KD9.
            $table->string('reference', 20)->unique();
            $table->foreignId('checkout_id')->nullable()->unique()->constrained()->nullOnDelete();
            // Nullable so an account deletion keeps the financial record.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_email');
            $table->string('customer_name')->nullable();
            $table->foreignId('organizer_id')->constrained()->restrictOnDelete();
            $table->foreignId('event_id')->constrained()->restrictOnDelete();
            $table->char('currency', 3);
            $table->bigInteger('subtotal_minor');
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('platform_fee_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('total_minor');
            $table->bigInteger('refunded_minor')->default(0);
            $table->string('status', 24)->default('pending_payment');
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['organizer_id', 'status', 'created_at']);
            $table->index(['event_id', 'status']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('promo_code_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promo_code_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->char('currency', 3);
            $table->bigInteger('discount_minor');
            $table->timestamps();

            $table->index(['promo_code_id', 'user_id']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_type_id')->constrained()->restrictOnDelete();
            // Snapshots: later edits to the ticket type never change old orders.
            $table->string('ticket_name_snapshot');
            $table->unsignedInteger('quantity');
            $table->bigInteger('unit_amount_minor');
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('line_total_minor');
            $table->bigInteger('fee_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE checkouts ADD CONSTRAINT checkouts_status_check CHECK (status IN ('open','awaiting_payment','completed','expired','cancelled'))");
            DB::statement('ALTER TABLE checkouts ADD CONSTRAINT checkouts_amounts_check CHECK (subtotal_minor >= 0 AND discount_minor >= 0 AND fees_minor >= 0 AND tax_minor >= 0 AND total_minor >= 0)');

            DB::statement('ALTER TABLE inventory_reservations ADD CONSTRAINT inventory_reservations_quantity_check CHECK (quantity > 0)');
            DB::statement("ALTER TABLE inventory_reservations ADD CONSTRAINT inventory_reservations_status_check CHECK (status IN ('active','converted','released','expired'))");

            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('pending_payment','paid','partially_refunded','refunded','cancelled','payment_failed'))");
            DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_amounts_check CHECK (subtotal_minor >= 0 AND discount_minor >= 0 AND discount_minor <= subtotal_minor AND platform_fee_minor >= 0 AND tax_minor >= 0 AND total_minor >= 0)');
            DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_refund_check CHECK (refunded_minor >= 0 AND refunded_minor <= total_minor)');

            DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_quantity_check CHECK (quantity > 0)');
            DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_amounts_check CHECK (unit_amount_minor >= 0 AND discount_minor >= 0 AND line_total_minor >= 0 AND fee_minor >= 0 AND tax_minor >= 0)');

            DB::statement('ALTER TABLE promo_code_redemptions ADD CONSTRAINT promo_code_redemptions_amount_check CHECK (discount_minor >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('promo_code_redemptions');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('inventory_reservations');
        Schema::dropIfExists('checkouts');
    }
};
