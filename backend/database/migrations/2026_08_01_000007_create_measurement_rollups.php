<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        // Hourly rollup. Trend charts and baseline comparisons read this rather
        // than scanning raw samples, and it survives raw-data retention so a
        // year-over-year comparison is still possible after raw rows expire.
        DB::statement(<<<'SQL'
            CREATE MATERIALIZED VIEW measurements_hourly
            WITH (timescaledb.continuous) AS
            SELECT
                time_bucket(INTERVAL '1 hour', time) AS bucket,
                appliance_id,
                sensor_id,
                channel_key,
                unit,
                count(*)              AS samples,
                count(*) FILTER (WHERE quality <> 'good') AS degraded_samples,
                avg(value)            AS avg_value,
                min(value)            AS min_value,
                max(value)            AS max_value,
                stddev_samp(value)    AS stddev_value
            FROM measurements
            WHERE value IS NOT NULL
            GROUP BY bucket, appliance_id, sensor_id, channel_key, unit
            WITH NO DATA
        SQL);

        DB::statement(<<<'SQL'
            SELECT add_continuous_aggregate_policy('measurements_hourly',
                start_offset => INTERVAL '3 days',
                end_offset   => INTERVAL '1 hour',
                schedule_interval => INTERVAL '30 minutes')
        SQL);

        DB::statement("SELECT add_retention_policy('measurements_hourly', INTERVAL '10 years')");

        DB::statement('CREATE INDEX measurements_hourly_channel_idx ON measurements_hourly (sensor_id, channel_key, bucket DESC)');
    }

    public function down(): void
    {
        DB::statement('DROP MATERIALIZED VIEW IF EXISTS measurements_hourly CASCADE');
    }
};
