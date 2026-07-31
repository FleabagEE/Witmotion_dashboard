<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table): void {
            // Structural monitoring is a different domain from machine condition
            // monitoring, and uses different standards. An asset declares which
            // it is rather than the code assuming.
            $table->string('monitoring_domain', 30)->default('machinery')->after('asset_type');

            // Which standard governs, e.g. din4150_3, din4150_3_long_term, bs7385_2.
            $table->string('vibration_standard', 40)->nullable()->after('iso_10816_class');
            // Structure class within that standard: commercial / residential /
            // sensitive, or reinforced / unreinforced for BS 7385-2.
            $table->string('structure_class', 40)->nullable()->after('vibration_standard');
            // Guideline values differ sharply between the foundation and the
            // topmost floor, because the building amplifies motion with height.
            $table->string('measurement_position', 30)->nullable()->after('structure_class');
            $table->string('construction_type', 80)->nullable()->after('measurement_position');
            $table->unsignedSmallInteger('storeys')->nullable()->after('construction_type');
            $table->integer('year_built')->nullable()->after('storeys');

            $table->index(['monitoring_domain', 'vibration_standard']);
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table): void {
            $table->dropIndex(['monitoring_domain', 'vibration_standard']);
            $table->dropColumn([
                'monitoring_domain', 'vibration_standard', 'structure_class',
                'measurement_position', 'construction_type', 'storeys', 'year_built',
            ]);
        });
    }
};
