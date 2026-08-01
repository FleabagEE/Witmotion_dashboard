<?php

namespace Tests\Feature;

use App\Events\LiveMeasurement;
use Tests\TestCase;

/**
 * The live bridge relays the acquisition feed onto websockets.
 *
 * Its two historical failure modes were both silent-ish: a connection whose
 * read timeout killed the blocking subscribe after a few idle seconds, and a
 * key prefix that made it subscribe to a channel nobody publishes to. The first
 * at least crashed; the second would have sat there looking healthy and
 * delivering nothing. Both are pinned here.
 */
class LiveBridgeTest extends TestCase
{
    public function test_the_subscriber_connection_has_no_read_timeout(): void
    {
        // A blocking SUBSCRIBE is idle between readings. Any read timeout would
        // treat that silence as a dead connection and tear the bridge down.
        $this->assertSame(0, config('database.redis.live.read_timeout'));
    }

    public function test_the_subscriber_connection_does_not_prefix_channel_names(): void
    {
        // phpredis applies the key prefix to channel names as well as keys, so
        // a prefix here means subscribing to a channel the acquisition service
        // never publishes to - and receiving nothing, quietly.
        $this->assertSame('', config('database.redis.live.options.prefix'));
    }

    public function test_other_connections_keep_their_read_timeout(): void
    {
        // The exemption is for the subscriber alone. On cache and queue traffic
        // a read that never returns is a genuine fault and must still time out.
        $this->assertNull(config('database.redis.cache.read_timeout'));
    }

    public function test_the_subscriber_reads_the_same_database_the_publisher_writes(): void
    {
        $this->assertSame(
            config('database.redis.default.database'),
            config('database.redis.live.database'),
        );
    }

    public function test_live_measurements_broadcast_on_a_per_sensor_channel(): void
    {
        $event = new LiveMeasurement([
            'sensor_id' => 'SENSOR-001',
            'group' => 'acceleration',
            't' => 1785499200000,
            'values' => ['accel_x' => 0.5],
        ]);

        // Per sensor, so a browser watching one sensor is not woken for every
        // reading from every other one on the appliance.
        $this->assertSame('sensor.SENSOR-001', $event->broadcastOn()->name);
        $this->assertSame('measurement', $event->broadcastAs());
    }

    public function test_the_live_channel_carries_no_alarm_or_threshold_state(): void
    {
        $payload = (new LiveMeasurement([
            'sensor_id' => 'SENSOR-001',
            'group' => 'acceleration',
            't' => 1785499200000,
            'values' => ['accel_x' => 0.5],
        ]))->broadcastWith();

        // The live feed is a view, not a record: it may drop frames by design.
        // Anything acted upon - an alarm, a threshold, a report - has to come
        // from the stored series, so none of that may travel this way and be
        // mistaken for authoritative.
        $this->assertArrayNotHasKey('alarm', $payload);
        $this->assertArrayNotHasKey('level', $payload);
        $this->assertArrayNotHasKey('threshold', $payload);
    }
}
