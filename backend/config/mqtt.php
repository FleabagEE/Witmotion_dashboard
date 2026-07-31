<?php

return [
    // Outbound integration only. Measurements enter through the authenticated
    // ingestion API; MQTT never writes to the historical record, so a
    // compromised broker cannot inject readings.
    'enabled' => env('MQTT_ENABLED', false),
    'host' => env('MQTT_HOST', '127.0.0.1'),
    'port' => (int) env('MQTT_PORT', 1884),
    'username' => env('MQTT_USERNAME', 'quakevault'),
    'password' => env('MQTT_PASSWORD'),
    'client_id' => env('MQTT_CLIENT_ID', 'quakevault-backend'),
    'tls' => (bool) env('MQTT_TLS', false),
    'ca_file' => env('MQTT_CA_FILE'),
    'connect_timeout' => (int) env('MQTT_CONNECT_TIMEOUT', 5),

    'topic_root' => env('MQTT_TOPIC_ROOT', 'quakevault'),
    'appliance_id' => env('MQTT_APPLIANCE_ID', 'QV-EDGE-001'),

    // QoS 1 for alarms: an integration must not miss one because a packet was
    // dropped. Status and health are QoS 0 - they are superseded within seconds
    // and a lost one costs nothing.
    'qos' => ['alarm' => 1, 'status' => 0, 'health' => 0],
    'retain' => ['alarm' => false, 'status' => true, 'health' => true],
];
