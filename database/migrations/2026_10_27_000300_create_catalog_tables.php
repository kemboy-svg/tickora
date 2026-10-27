<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('venues', function (Blueprint $table) {
            $table->id();
            // Null for shared venues curated by the platform.
            $table->foreignId('organizer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('address_line');
            $table->string('city');
            $table->string('region')->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->char('country_code', 2);
            // IANA timezone, e.g. "Indian/Mauritius".
            $table->string('timezone', 64);
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->timestamps();

            $table->index(['organizer_id', 'name']);
        });

        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organizer_id')->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->foreignId('venue_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('summary', 280)->nullable();
            $table->text('description')->nullable();
            $table->string('cover_image_key')->nullable();
            // Display timezone; all timestamps are stored in UTC.
            $table->string('timezone', 64);
            // MVP: one currency per event, and therefore per order.
            $table->char('currency', 3);
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->timestampTz('sales_starts_at')->nullable();
            $table->timestampTz('sales_ends_at')->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('visibility', 20)->default('public');
            $table->text('refund_policy')->nullable();
            $table->unsignedTinyInteger('min_age')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_notes')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index(['status', 'starts_at']);
            $table->index(['organizer_id', 'status']);
            $table->index(['category_id', 'status', 'starts_at']);
        });

        Schema::create('event_staff_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['event_id', 'user_id']);
            $table->index(['user_id', 'revoked_at']);
        });

        Schema::create('ticket_types', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->char('currency', 3);
            $table->bigInteger('unit_amount_minor');
            $table->unsignedInteger('quantity_total');
            // Transactionally maintained counter. Order items and reservations
            // remain the source of truth; reconciliation re-derives it.
            $table->unsignedInteger('quantity_sold')->default(0);
            $table->timestampTz('sales_starts_at')->nullable();
            $table->timestampTz('sales_ends_at')->nullable();
            $table->unsignedSmallInteger('min_per_order')->default(1);
            $table->unsignedSmallInteger('max_per_order')->default(10);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->index(['event_id', 'status', 'sort_order']);
        });

        Schema::create('promo_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizer_id')->constrained()->cascadeOnDelete();
            // Null = valid for every event of the organiser.
            $table->foreignId('event_id')->nullable()->constrained()->cascadeOnDelete();
            // Stored uppercase; matched case-insensitively.
            $table->string('code', 40);
            $table->string('discount_type', 10);
            // Basis points for "percent", minor units for "fixed".
            $table->bigInteger('discount_value');
            // Required for "fixed" discounts.
            $table->char('currency', 3)->nullable();
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedInteger('redemptions_count')->default(0);
            $table->unsignedSmallInteger('max_per_customer')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organizer_id', 'code']);
        });

        // Optional restriction of a promo code to specific ticket types.
        Schema::create('promo_code_ticket_type', function (Blueprint $table) {
            $table->foreignId('promo_code_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_type_id')->constrained()->cascadeOnDelete();

            $table->primary(['promo_code_id', 'ticket_type_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE events ADD CONSTRAINT events_time_order_check CHECK (ends_at > starts_at)');
            DB::statement('ALTER TABLE events ADD CONSTRAINT events_sales_window_check CHECK (sales_starts_at IS NULL OR sales_ends_at IS NULL OR sales_ends_at > sales_starts_at)');
            DB::statement("ALTER TABLE events ADD CONSTRAINT events_status_check CHECK (status IN ('draft','pending_review','published','cancelled','completed'))");
            DB::statement("ALTER TABLE events ADD CONSTRAINT events_visibility_check CHECK (visibility IN ('public','unlisted'))");

            DB::statement('ALTER TABLE ticket_types ADD CONSTRAINT ticket_types_amount_check CHECK (unit_amount_minor >= 0)');
            DB::statement('ALTER TABLE ticket_types ADD CONSTRAINT ticket_types_sold_check CHECK (quantity_total >= 0 AND quantity_sold >= 0 AND quantity_sold <= quantity_total)');
            DB::statement('ALTER TABLE ticket_types ADD CONSTRAINT ticket_types_per_order_check CHECK (min_per_order >= 1 AND max_per_order >= min_per_order)');
            DB::statement('ALTER TABLE ticket_types ADD CONSTRAINT ticket_types_sales_window_check CHECK (sales_starts_at IS NULL OR sales_ends_at IS NULL OR sales_ends_at > sales_starts_at)');
            DB::statement("ALTER TABLE ticket_types ADD CONSTRAINT ticket_types_status_check CHECK (status IN ('active','paused','hidden'))");

            DB::statement("ALTER TABLE promo_codes ADD CONSTRAINT promo_codes_type_check CHECK (discount_type IN ('percent','fixed'))");
            DB::statement("ALTER TABLE promo_codes ADD CONSTRAINT promo_codes_value_check CHECK (discount_value > 0 AND (discount_type <> 'percent' OR discount_value <= 10000))");
            DB::statement("ALTER TABLE promo_codes ADD CONSTRAINT promo_codes_currency_check CHECK (discount_type <> 'fixed' OR currency IS NOT NULL)");
            DB::statement('ALTER TABLE promo_codes ADD CONSTRAINT promo_codes_redemptions_check CHECK (max_redemptions IS NULL OR redemptions_count <= max_redemptions)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_code_ticket_type');
        Schema::dropIfExists('promo_codes');
        Schema::dropIfExists('ticket_types');
        Schema::dropIfExists('event_staff_assignments');
        Schema::dropIfExists('events');
        Schema::dropIfExists('venues');
        Schema::dropIfExists('categories');
    }
};
