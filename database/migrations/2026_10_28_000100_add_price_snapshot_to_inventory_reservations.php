<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Each reservation remembers the price it was quoted at, so the order
 * created from a checkout charges exactly what the customer was shown even
 * if the organiser edits the ticket type in the meantime.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_reservations', function (Blueprint $table) {
            $table->string('ticket_name_snapshot')->nullable()->after('quantity');
            $table->bigInteger('unit_amount_minor')->default(0)->after('ticket_name_snapshot');
            $table->bigInteger('subtotal_minor')->default(0)->after('unit_amount_minor');
            $table->bigInteger('discount_minor')->default(0)->after('subtotal_minor');
            $table->bigInteger('fee_minor')->default(0)->after('discount_minor');
            $table->bigInteger('tax_minor')->default(0)->after('fee_minor');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE inventory_reservations ADD CONSTRAINT inventory_reservations_amounts_check CHECK (unit_amount_minor >= 0 AND subtotal_minor >= 0 AND discount_minor >= 0 AND discount_minor <= subtotal_minor AND fee_minor >= 0 AND tax_minor >= 0)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE inventory_reservations DROP CONSTRAINT IF EXISTS inventory_reservations_amounts_check');
        }

        Schema::table('inventory_reservations', function (Blueprint $table) {
            $table->dropColumn(['ticket_name_snapshot', 'unit_amount_minor', 'subtotal_minor', 'discount_minor', 'fee_minor', 'tax_minor']);
        });
    }
};
