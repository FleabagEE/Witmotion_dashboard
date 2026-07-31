<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // batch_uid is a client-supplied correlation id, not necessarily a UUID.
        // The forwarder derives a *stable* id from the batch contents so that a
        // retry after a lost response is recognisable as the same batch; forcing
        // uuid here made every such retry fail with a type error.
        DB::statement('ALTER TABLE ingest_batches ALTER COLUMN batch_uid TYPE varchar(64) USING batch_uid::text');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ingest_batches ALTER COLUMN batch_uid TYPE uuid USING batch_uid::uuid');
    }
};
