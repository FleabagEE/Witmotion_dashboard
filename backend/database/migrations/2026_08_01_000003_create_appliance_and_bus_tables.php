<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appliances', function (Blueprint $table): void {
            $table->id();
            // The identifier the acquisition service stamps on every measurement.
            $table->string('appliance_id', 80)->unique();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 160);
            $table->string('software_version', 40)->nullable();
            $table->string('current_run_id', 64)->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('last_ingest_at')->nullable();
            $table->string('status', 40)->default('unknown');
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index('last_seen_at');
        });

        Schema::create('usb_adapters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('appliance_id')->constrained()->cascadeOnDelete();
            $table->string('adapter_id', 80);
            $table->string('chip', 60)->nullable();
            $table->string('usb_vendor_id', 8)->nullable();
            $table->string('usb_product_id', 8)->nullable();
            // Null on chips that expose no serial number (CH340), which is why
            // identity falls back to USB topology - see ADR-008.
            $table->string('usb_serial', 120)->nullable();
            $table->string('usb_topology', 80)->nullable();
            $table->string('alias_path', 160)->nullable();
            $table->timestamps();

            $table->unique(['appliance_id', 'adapter_id']);
        });

        Schema::create('rs485_buses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('appliance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('usb_adapter_id')->nullable()->constrained()->nullOnDelete();
            $table->string('bus_id', 80);
            $table->string('port_path', 160);
            $table->unsignedInteger('baud')->default(9600);
            $table->decimal('bus_utilisation', 5, 4)->nullable();
            $table->timestamps();

            $table->unique(['appliance_id', 'bus_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rs485_buses');
        Schema::dropIfExists('usb_adapters');
        Schema::dropIfExists('appliances');
    }
};
