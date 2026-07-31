<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alarm_definitions', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 80)->unique();
            $table->string('name', 160);
            $table->text('description')->nullable();

            // Scope: any of these may be null, meaning "applies to all".
            $table->foreignId('asset_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('sensor_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('channel_key', 60)->nullable();
            $table->string('quantity', 60)->nullable();

            $table->string('condition_type', 40)->default('high_threshold');
            $table->string('unit', 20)->nullable();

            // Ascending severity. Null means that level is not configured.
            $table->double('advisory_at')->nullable();
            $table->double('warning_at')->nullable();
            $table->double('critical_at')->nullable();

            // Hysteresis is subtracted from the raise threshold to get the clear
            // threshold. Without it a value sitting on a boundary produces an
            // endless raise/clear storm.
            $table->double('hysteresis')->default(0);
            // The condition must hold this long before the alarm raises, which is
            // what stops a single noisy sample from waking someone at 3am.
            $table->unsignedInteger('persistence_seconds')->default(0);
            // And this long below the clear threshold before it clears.
            $table->unsignedInteger('clear_seconds')->default(0);
            $table->unsignedInteger('debounce_seconds')->default(0);

            $table->boolean('latching')->default(false);
            $table->boolean('enabled')->default(true);
            $table->boolean('requires_verified_profile')->default(true);
            $table->string('source', 40)->default('manual');
            $table->jsonb('parameters')->nullable();
            $table->timestamps();

            $table->index(['enabled', 'quantity']);
            $table->index(['sensor_id', 'channel_key']);
        });

        Schema::create('alarm_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('alarm_definition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sensor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel_key', 60);

            $table->string('level', 20)->default('normal');
            $table->string('peak_level', 20)->default('normal');
            $table->string('state', 20)->default('active');

            $table->double('trigger_value')->nullable();
            $table->double('peak_value')->nullable();
            $table->double('threshold')->nullable();
            $table->string('unit', 20)->nullable();

            $table->timestampTz('raised_at');
            $table->timestampTz('last_evaluated_at')->nullable();
            $table->timestampTz('last_changed_at')->nullable();
            $table->timestampTz('cleared_at')->nullable();

            // Candidate state: the condition is met but has not yet held for
            // persistence_seconds. Nothing is announced until it does.
            $table->string('candidate_level', 20)->nullable();
            $table->timestampTz('candidate_since')->nullable();

            $table->timestampTz('acknowledged_at')->nullable();
            $table->unsignedBigInteger('acknowledged_by')->nullable();
            $table->text('acknowledgement_note')->nullable();
            $table->timestampTz('shelved_until')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            // One open event per definition and channel: an alarm that is already
            // active must escalate rather than spawn a duplicate.
            $table->index(['alarm_definition_id', 'sensor_id', 'channel_key', 'state'], 'alarm_open_idx');
            $table->index(['state', 'level']);
            $table->index(['raised_at']);
        });

        Schema::create('alarm_transitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('alarm_event_id')->constrained()->cascadeOnDelete();
            $table->string('from_level', 20);
            $table->string('to_level', 20);
            $table->string('reason', 80);
            $table->double('value')->nullable();
            $table->double('threshold')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->timestampTz('occurred_at');

            $table->index(['alarm_event_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alarm_transitions');
        Schema::dropIfExists('alarm_events');
        Schema::dropIfExists('alarm_definitions');
    }
};
