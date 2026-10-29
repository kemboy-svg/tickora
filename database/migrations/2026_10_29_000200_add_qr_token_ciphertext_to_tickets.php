<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ticket page must be able to show a ticket's QR code again, so the
 * token is kept encrypted with the application key alongside its hash.
 * Scanning still looks tickets up by hash only; a copy of the database
 * without the key does not reveal usable QR codes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->text('qr_token_ciphertext')->nullable()->after('qr_token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('qr_token_ciphertext');
        });
    }
};
