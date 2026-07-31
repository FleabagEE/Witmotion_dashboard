<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS timescaledb');
    }

    public function down(): void
    {
        // Deliberately not dropped: the extension owns the hypertables, and
        // dropping it would take the measurement history with it.
    }
};
