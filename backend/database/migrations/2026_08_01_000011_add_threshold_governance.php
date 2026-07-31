<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alarm_definitions', function (Blueprint $table): void {
            // Who signed off these numbers, against what, and when.
            //
            // Threshold values that came from software rather than from a named
            // person checking a real source must not page anybody. The standards
            // are copyrighted documents this project does not hold, so the
            // shipped values are a starting point and nothing more until someone
            // who owns the risk confirms them.
            $table->timestampTz('thresholds_confirmed_at')->nullable()->after('source');
            $table->string('thresholds_confirmed_by', 160)->nullable()->after('thresholds_confirmed_at');
            $table->string('thresholds_reference', 240)->nullable()->after('thresholds_confirmed_by');
            $table->text('thresholds_note')->nullable()->after('thresholds_reference');

            $table->index('thresholds_confirmed_at');
        });

        Schema::table('alarm_events', function (Blueprint $table): void {
            // Raised from thresholds nobody has confirmed. Shown on the
            // dashboard, excluded from notifications.
            $table->boolean('provisional')->default(false)->after('state');
            $table->index(['provisional', 'state']);
        });
    }

    public function down(): void
    {
        Schema::table('alarm_events', function (Blueprint $table): void {
            $table->dropIndex(['provisional', 'state']);
            $table->dropColumn('provisional');
        });
        Schema::table('alarm_definitions', function (Blueprint $table): void {
            $table->dropIndex(['thresholds_confirmed_at']);
            $table->dropColumn([
                'thresholds_confirmed_at', 'thresholds_confirmed_by',
                'thresholds_reference', 'thresholds_note',
            ]);
        });
    }
};
