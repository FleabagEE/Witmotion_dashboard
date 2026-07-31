<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_channels', function (Blueprint $table): void {
            // A channel that only hears about alarms nobody acknowledged.
            // Without this, an escalation target also receives the first
            // message, so escalating just tells the same person twice.
            $table->boolean('escalation_only')->default(false)->after('enabled');
        });
    }

    public function down(): void
    {
        Schema::table('notification_channels', function (Blueprint $table): void {
            $table->dropColumn('escalation_only');
        });
    }
};
