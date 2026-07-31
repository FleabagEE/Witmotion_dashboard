<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sensor_models', function (Blueprint $table): void {
            $table->id();
            $table->string('model', 80)->unique();
            $table->string('manufacturer', 120);
            $table->string('protocol', 40)->default('modbus_rtu');
            $table->string('profile_version', 40);
            // Mirrors the acquisition profile. Only 'verified' may drive alarms
            // (ADR-005); the backend re-checks rather than trusting the sender.
            $table->string('verification_status', 20)->default('unverified');
            $table->jsonb('capabilities')->nullable();
            $table->jsonb('limitations')->nullable();
            $table->timestamps();
        });

        Schema::create('sensors', function (Blueprint $table): void {
            $table->id();
            $table->string('sensor_id', 80);
            $table->foreignId('appliance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rs485_bus_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sensor_model_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('slave_id')->nullable();
            $table->string('mount_location', 160)->nullable();
            $table->string('mount_method', 60)->nullable();
            $table->string('status', 40)->default('active');
            $table->date('installed_on')->nullable();
            $table->timestampTz('last_measurement_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['appliance_id', 'sensor_id']);
            $table->index(['asset_id', 'status']);
        });

        Schema::create('channels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sensor_id')->constrained()->cascadeOnDelete();
            $table->string('channel_key', 60);
            $table->string('group_key', 60);
            $table->string('label', 160);
            $table->string('quantity', 60);
            $table->string('unit', 20);
            $table->string('value_class', 20)->default('native');

            // Decoding provenance: which register produced this, and how. Stored
            // so a historical value can always be explained, even after the
            // profile changes.
            $table->unsignedInteger('register_address')->nullable();
            $table->string('data_type', 20)->nullable();
            $table->double('scale')->nullable();
            $table->double('offset')->nullable();
            $table->double('range_min')->nullable();
            $table->double('range_max')->nullable();

            $table->boolean('enabled')->default(true);
            $table->double('configured_hz')->nullable();
            $table->double('measured_hz')->nullable();
            $table->double('jitter_ms')->nullable();
            $table->timestamps();

            $table->unique(['sensor_id', 'channel_key']);
            $table->index(['sensor_id', 'group_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channels');
        Schema::dropIfExists('sensors');
        Schema::dropIfExists('sensor_models');
    }
};
