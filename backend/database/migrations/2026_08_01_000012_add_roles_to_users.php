<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Roles from the appliance specification. Kiosk is deliberately the
            // weakest: a screen on a wall in a public corridor must not be able
            // to acknowledge an alarm or change a threshold.
            $table->string('role', 30)->default('viewer')->after('email');
            $table->boolean('active')->default(true)->after('role');
            $table->timestampTz('last_login_at')->nullable()->after('active');
            $table->index(['role', 'active']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['role', 'active']);
            $table->dropColumn(['role', 'active', 'last_login_at']);
        });
    }
};
