<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Continuous aggregates and some policy calls cannot run inside a
    // transaction block, so this migration manages its own.
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE measurements (
                time             timestamptz      NOT NULL,
                appliance_id     text             NOT NULL,
                sensor_id        text             NOT NULL,
                channel_key      text             NOT NULL,
                channel_id       bigint,
                poll_id          bigint,
                value            double precision,
                unit             text             NOT NULL,
                quality          text             NOT NULL DEFAULT 'good',
                source_type      text             NOT NULL DEFAULT 'native',
                sequence         bigint           NOT NULL,
                run_id           text             NOT NULL,
                raw_registers    integer[],
                profile_version  text,
                processing_version text,
                ingested_at      timestamptz      NOT NULL DEFAULT now()
            )
        SQL);

        // One day per chunk: at the appliance's real rates a day is a few
        // hundred thousand rows, which keeps chunks small enough to compress
        // and drop cheaply.
        DB::statement("SELECT create_hypertable('measurements', 'time', chunk_time_interval => INTERVAL '1 day')");

        DB::statement('CREATE INDEX measurements_sensor_channel_time_idx ON measurements (sensor_id, channel_key, time DESC)');
        DB::statement('CREATE INDEX measurements_appliance_time_idx ON measurements (appliance_id, time DESC)');
        DB::statement('CREATE INDEX measurements_channel_time_idx ON measurements (channel_id, time DESC)');
        DB::statement("CREATE INDEX measurements_quality_idx ON measurements (quality, time DESC) WHERE quality <> 'good'");

        // Compression keeps a year of history affordable. Segmenting by the
        // columns we filter on is what makes compressed chunks still queryable
        // at a sensible speed.
        DB::statement(<<<'SQL'
            ALTER TABLE measurements SET (
                timescaledb.compress,
                timescaledb.compress_segmentby = 'sensor_id, channel_key',
                timescaledb.compress_orderby = 'time DESC'
            )
        SQL);
        DB::statement("SELECT add_compression_policy('measurements', INTERVAL '7 days')");

        // Raw retention is configurable per deployment; the hourly rollup below
        // outlives it, so long-term trends survive raw expiry.
        DB::statement("SELECT add_retention_policy('measurements', INTERVAL '365 days')");
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS measurements CASCADE');
    }
};
