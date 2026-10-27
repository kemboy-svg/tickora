<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            // "user", "system" (queued jobs, schedules) or "webhook".
            $table->string('actor_type', 20)->default('user');
            $table->string('action', 80);
            $table->nullableMorphs('subject');
            // Lets organisers see their own trail without scanning everything.
            $table->foreignId('organizer_id')->nullable()->constrained()->nullOnDelete();
            // Safe metadata only. Never secrets, tokens or card data.
            $table->jsonb('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['action', 'created_at']);
            $table->index(['organizer_id', 'created_at']);
            $table->index(['actor_user_id', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            // Audit rows can be read and appended, never edited. The one
            // permitted change is a foreign key being nulled because the user
            // or organiser it pointed to was deleted (ON DELETE SET NULL);
            // the action, subject, metadata and timestamp stay untouched.
            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION audit_logs_no_update() RETURNS trigger AS $$
                BEGIN
                    IF (NEW.id, NEW.actor_type, NEW.action, NEW.subject_type, NEW.subject_id,
                        NEW.metadata::text, NEW.ip_address, NEW.user_agent, NEW.created_at)
                       IS NOT DISTINCT FROM
                       (OLD.id, OLD.actor_type, OLD.action, OLD.subject_type, OLD.subject_id,
                        OLD.metadata::text, OLD.ip_address, OLD.user_agent, OLD.created_at)
                       AND (NEW.actor_user_id IS NULL OR NEW.actor_user_id = OLD.actor_user_id)
                       AND (NEW.organizer_id IS NULL OR NEW.organizer_id = OLD.organizer_id)
                    THEN
                        RETURN NEW;
                    END IF;

                    RAISE EXCEPTION 'audit_logs rows cannot be modified';
                END;
                $$ LANGUAGE plpgsql
            SQL);

            DB::statement(<<<'SQL'
                CREATE TRIGGER audit_logs_no_update
                BEFORE UPDATE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION audit_logs_no_update()
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS audit_logs_no_update ON audit_logs');
            DB::statement('DROP FUNCTION IF EXISTS audit_logs_no_update()');
        }

        Schema::dropIfExists('audit_logs');
    }
};
