<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The idempotency ledger. Deliberately a plain table rather than part of
        // the hypertable: TimescaleDB requires every unique index to include the
        // partitioning column, which would make the key time-dependent and
        // therefore useless for deduplicating a replayed batch.
        Schema::create('ingested_polls', function (Blueprint $table): void {
            $table->id();
            $table->string('idempotency_key', 200)->unique();
            $table->string('appliance_id', 80);
            $table->string('run_id', 64);
            $table->string('sensor_id', 80);
            $table->string('group_key', 60);
            $table->bigInteger('sequence');
            $table->timestampTz('measured_at');
            $table->string('quality', 20)->default('good');
            $table->double('latency_ms')->nullable();
            $table->unsignedSmallInteger('channel_count')->default(0);
            $table->boolean('simulated')->default(false);
            $table->timestampTz('ingested_at')->useCurrent();

            $table->index(['appliance_id', 'measured_at']);
            $table->index(['sensor_id', 'group_key', 'measured_at']);
            $table->index(['run_id']);
        });

        Schema::create('ingest_batches', function (Blueprint $table): void {
            $table->id();
            $table->uuid('batch_uid')->unique();
            $table->string('appliance_id', 80);
            $table->unsignedInteger('offered')->default(0);
            $table->unsignedInteger('accepted')->default(0);
            $table->unsignedInteger('duplicates')->default(0);
            $table->unsignedInteger('rejected')->default(0);
            $table->string('status', 20)->default('accepted');
            $table->text('error')->nullable();
            $table->string('source_ip', 60)->nullable();
            $table->timestampTz('received_at')->useCurrent();

            $table->index(['appliance_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingest_batches');
        Schema::dropIfExists('ingested_polls');
    }
};
