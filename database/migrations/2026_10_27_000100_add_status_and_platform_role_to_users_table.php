<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('status', 20)->default('active')->after('password')->index();
            // Platform staff role (admin, finance, support). Null for customers
            // and organisers; organiser roles live on organizer_members.
            $table->string('platform_role', 20)->nullable()->after('status')->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['status', 'platform_role']);
        });
    }
};
