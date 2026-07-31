<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table): void {
            $table->id();
            $table->string('report_uid', 40)->unique();
            $table->string('type', 40);
            $table->string('title', 240);

            // Everything needed to regenerate this exact document. A report that
            // cannot be reproduced is an assertion, not evidence - and these are
            // the documents that end up attached to a damage claim.
            $table->jsonb('parameters');
            $table->timestampTz('window_from');
            $table->timestampTz('window_to');
            $table->string('timezone', 60)->default('UTC');
            $table->string('software_version', 40);
            $table->string('processing_version', 40);
            $table->string('standard_tables_status', 20)->nullable();

            $table->string('generated_by', 160);
            $table->timestampTz('generated_at')->useCurrent();
            $table->string('content_checksum', 64)->nullable();
            $table->unsignedInteger('row_count')->default(0);

            $table->index(['type', 'generated_at']);
            $table->index(['window_from', 'window_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
