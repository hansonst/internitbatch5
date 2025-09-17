<?php
return [
    'broker' => env('MQTT_BROKER', '192.102.30.79'),
    'port' => env('MQTT_PORT', 1883),
    'weight_topic' => env('MQTT_WEIGHT_TOPIC', 'timbangan/weight'),
    'timeout' => 5,
];