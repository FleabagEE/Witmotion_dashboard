# MQTT topics

One-way, on purpose. Measurements enter the system through the authenticated
ingestion API and nowhere else, so a compromised broker can mislead a SCADA
screen but cannot put a forged reading into the historical record.

Publishing must never break the thing it reports on. Every failure is logged and
swallowed: an unreachable broker is an integration outage, not a monitoring
outage, and the database already holds the truth.

**Disabled by default.** Nothing attempts a connection unless `MQTT_ENABLED=true`.

## Topic structure

```
{topic_root}/{appliance_id}/…      default root: quakevault
```

| Topic | QoS | Retained | When |
|---|---|---|---|
| `quakevault/{id}/alarms` | 1 | no | An alarm is raised, cleared or acknowledged |
| `quakevault/{id}/status` | 1 | **yes** | Connect, clean disconnect, and as a last will |
| `quakevault/{id}/health` | 0 | **yes** | Every minute (`mqtt:health`) |
| `quakevault/{id}/sensors/{sensor}/status` | 0 | **yes** | Sensor goes online or silent |
| `quakevault/{id}/sensors/{sensor}/measurements` | 0 | no | Periodic snapshot |

### Why those QoS and retain choices

**Alarms are QoS 1 and not retained.** An integration must not miss an alarm
because a packet was dropped — hence QoS 1. But a retained alarm would be
replayed to every new subscriber and misread as live, so it is not retained.

**Status and health are retained.** A subscriber connecting at any moment learns
the current state immediately instead of waiting for the next event.

**A last will is registered.** If the publisher dies, subscribers are told the
appliance went offline rather than being left with a stale retained `online` that
never changes.

## Payloads

Every payload carries `schema_version`.

### `alarms`

```json
{
  "schema_version": "1.0",
  "event_id": 42,
  "name": "Structural vibration",
  "sensor_id": 1,
  "channel_key": "vib_velocity_x",
  "level": "critical",
  "peak_level": "critical",
  "state": "active",
  "value": 8.2,
  "threshold": 5.0,
  "unit": "mm/s",
  "raised_at": "2026-08-03T20:15:02+00:00",
  "acknowledged": false,
  "provisional": true,
  "thresholds_confirmed_by": null
}
```

**`provisional` travels on the wire deliberately.** An integration receives
alarms raised from unverified thresholds — it is consuming data, not being paged
— but nothing downstream may mistake one for confirmed. `thresholds_confirmed_by`
names the person who checked, or is null.

### `health`

```json
{
  "schema_version": "1.0",
  "at": "2026-08-03T20:15:02+00:00",
  "sensors_total": 1,
  "sensors_online": 1,
  "standard_tables_status": "candidate"
}
```

`standard_tables_status` is there so a downstream system never has to ask whether
the guideline values were ever verified.

## Security

`allow_anonymous false`. Two credentials, and the ACL enforces the asymmetry:

```
user quakevault
topic write quakevault/#
topic read  quakevault/#

user integration
topic read  quakevault/#
```

Nothing that subscribes may write, so a leaked subscriber credential cannot be
used to forge an alarm. Verified by observing that a publish from the
`integration` credential is discarded rather than delivered.

**The broker healthcheck authenticates and publishes inside `quakevault/#`.** It
used to read `$SYS/broker/uptime`, which the ACL deliberately does not grant —
the check failed 7665 times in a row against a working broker. A health signal
that is permanently red trains people to ignore it.

## Configuration

```
MQTT_ENABLED=true
MQTT_HOST=127.0.0.1
MQTT_PORT=1884          # 1883 is taken on this host
MQTT_USERNAME=quakevault
MQTT_PASSWORD=…
MQTT_APPLIANCE_ID=QV-EDGE-001
MQTT_TLS=false
MQTT_CA_FILE=
```

```bash
php artisan mqtt:health
```

Exits 0 even with no broker. An integration outage must not fail a health check
that operations watches.
