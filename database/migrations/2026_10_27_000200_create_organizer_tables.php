<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizers', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->char('country_code', 2);
            $table->char('default_currency', 3);
            // Currency the organiser is paid out in; set once a payout account exists.
            $table->char('payout_currency', 3)->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->string('support_email')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('organizer_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20);
            $table->string('status', 20)->default('active');
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('joined_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['organizer_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('organizer_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizer_id')->constrained()->cascadeOnDelete();
            // "manual" for in-house review, or a KYC provider's name.
            $table->string('provider', 40)->default('manual');
            $table->string('provider_reference')->nullable();
            $table->string('status', 20)->default('submitted');
            // Object-storage keys in the private bucket. Never public paths.
            $table->jsonb('document_keys')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();

            $table->index(['organizer_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE organizers ADD CONSTRAINT organizers_status_check CHECK (status IN ('pending','verified','suspended','rejected'))");
            DB::statement("ALTER TABLE organizer_members ADD CONSTRAINT organizer_members_role_check CHECK (role IN ('owner','manager','staff'))");
            DB::statement("ALTER TABLE organizer_members ADD CONSTRAINT organizer_members_status_check CHECK (status IN ('invited','active','revoked'))");
            // At most one active owner per organiser.
            DB::statement("CREATE UNIQUE INDEX organizer_members_one_owner ON organizer_members (organizer_id) WHERE role = 'owner' AND status = 'active'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('organizer_verifications');
        Schema::dropIfExists('organizer_members');
        Schema::dropIfExists('organizers');
    }
};
