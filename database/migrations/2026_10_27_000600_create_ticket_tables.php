<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            // Customer-facing reference, e.g. TKR-7Q4M-2KD9-01.
            $table->string('reference', 24)->unique();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('event_id')->constrained()->restrictOnDelete();
            $table->foreignId('ticket_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('holder_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('attendee_name')->nullable();
            $table->string('attendee_email')->nullable();
            // SHA-256 of the QR token. The raw token is never stored.
            $table->char('qr_token_hash', 64)->unique();
            $table->string('status', 20)->default('valid');
            $table->timestampTz('issued_at');
            $table->timestampTz('checked_in_at')->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'status']);
            $table->index(['holder_user_id', 'status']);
        });

        Schema::create('check_ins', function (Blueprint $table) {
            $table->id();
            // Null when the scanned code matched no ticket.
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('event_id')->constrained()->restrictOnDelete();
            $table->foreignId('staff_user_id')->constrained('users')->restrictOnDelete();
            // Client-generated per scan; makes retries idempotent.
            $table->uuid('scanner_request_id')->unique();
            $table->string('result', 24);
            $table->timestampTz('scanned_at');
            $table->string('device_label', 80)->nullable();
            $table->timestamps();

            $table->index(['event_id', 'scanned_at']);
            $table->index('ticket_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_status_check CHECK (status IN ('valid','checked_in','cancelled','refunded','void'))");
            DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_checked_in_at_check CHECK (status <> 'checked_in' OR checked_in_at IS NOT NULL)");
            DB::statement("ALTER TABLE check_ins ADD CONSTRAINT check_ins_result_check CHECK (result IN ('admitted','already_checked_in','invalid','refunded','cancelled','wrong_event','event_not_active'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('check_ins');
        Schema::dropIfExists('tickets');
    }
};
