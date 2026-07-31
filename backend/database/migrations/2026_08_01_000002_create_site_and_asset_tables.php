<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('name', 160);
            $table->string('timezone', 60)->default('UTC');
            $table->string('address', 240)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 80);
            $table->string('name', 160);
            $table->string('asset_type', 80)->default('machine');

            // ISO 10816 machine class drives the default vibration-velocity
            // alarm thresholds, so it is part of the asset, not the sensor.
            $table->string('iso_10816_class', 20)->nullable();
            $table->decimal('rated_power_kw', 10, 2)->nullable();
            $table->unsignedInteger('nominal_rpm')->nullable();
            $table->string('status', 40)->default('active');
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'slug']);
            $table->index(['site_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
        Schema::dropIfExists('sites');
    }
};
