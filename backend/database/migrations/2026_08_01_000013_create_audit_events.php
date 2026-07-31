<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table): void {
            $table->id();
            $table->timestampTz('occurred_at')->useCurrent();

            // Who. Denormalised on purpose: an audit record must stay readable
            // after the account is renamed or deleted.
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name', 160)->nullable();
            $table->string('actor_role', 30)->nullable();
            $table->string('actor_type', 30)->default('user');

            // What.
            $table->string('action', 80);
            $table->string('subject_type', 80)->nullable();
            $table->string('subject_id', 120)->nullable();
            $table->string('summary', 500)->nullable();
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();

            // Where from.
            $table->string('ip_address', 60)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('result', 20)->default('success');

            $table->index(['occurred_at']);
            $table->index(['action', 'occurred_at']);
            $table->index(['actor_id', 'occurred_at']);
            $table->index(['subject_type', 'subject_id']);
        });

        // An audit trail that can be edited is not evidence. Updates and deletes
        // are refused at the database, so no application bug, ORM call or
        // compromised web account can quietly rewrite history. Removing the log
        // wholesale still requires DDL rights, which the application user should
        // not hold in production.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_events_are_append_only()
            RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION 'audit_events is append-only: % is not permitted', TG_OP;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER audit_events_no_update_delete
            BEFORE UPDATE OR DELETE ON audit_events
            FOR EACH ROW EXECUTE FUNCTION audit_events_are_append_only();
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS audit_events_no_update_delete ON audit_events');
        DB::statement('DROP FUNCTION IF EXISTS audit_events_are_append_only()');
        Schema::dropIfExists('audit_events');
    }
};
