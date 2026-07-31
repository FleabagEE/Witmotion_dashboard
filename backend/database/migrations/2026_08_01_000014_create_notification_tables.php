<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_channels', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 80)->unique();
            $table->string('name', 160);
            $table->string('transport', 30);            // email | webhook | log
            $table->jsonb('config')->nullable();        // recipients, url, headers
            $table->boolean('enabled')->default(true);

            // Severity floor. An advisory at 3am is how people learn to ignore
            // the channel that matters.
            $table->string('min_level', 20)->default('warning');

            // Quiet hours suppress non-critical traffic. Critical always goes.
            $table->time('quiet_from')->nullable();
            $table->time('quiet_to')->nullable();

            // Bounds on volume: a flapping input must not be able to send a
            // thousand messages, and repeats of the same condition are pointless.
            $table->unsignedInteger('max_per_hour')->default(30);
            $table->unsignedInteger('dedupe_window_seconds')->default(900);

            $table->unsignedSmallInteger('escalate_after_minutes')->nullable();
            $table->string('escalates_to', 80)->nullable();

            $table->timestamps();
            $table->index(['enabled', 'min_level']);
        });

        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('alarm_event_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('notification_channel_id')->constrained()->cascadeOnDelete();

            $table->string('dedupe_key', 200);
            $table->string('level', 20);
            $table->string('subject', 240);
            $table->text('body')->nullable();

            $table->string('status', 20)->default('pending'); // pending|sent|failed|suppressed
            $table->string('suppressed_reason', 80)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['notification_channel_id', 'created_at']);
            $table->index(['dedupe_key', 'created_at']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notification_channels');
    }
};
