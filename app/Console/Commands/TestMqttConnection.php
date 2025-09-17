<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;

class TestMqttConnection extends Command
{
    protected $signature = 'mqtt:test';
    protected $description = 'Test MQTT connection';

    public function handle()
    {
        $broker = config('mqtt.broker');
        $port = config('mqtt.port');
        
        $this->info("Testing MQTT connection...");
        $this->info("Broker: {$broker}:{$port}");
        
        try {
            $connectionSettings = new ConnectionSettings();
            $connectionSettings->setConnectTimeout(10);
            
            $mqtt = new MqttClient($broker, $port, 'test-client-' . uniqid());
            
            $this->info("Connecting...");
            $mqtt->connect($connectionSettings);
            
            $this->info("✅ Connection successful!");
            
            // Test subscribe
            $this->info("Testing subscription...");
            $mqtt->subscribe('test/topic', function ($topic, $message) {
                $this->info("Received test message: {$message}");
            });
            
            // Test publish
            $this->info("Publishing test message...");
            $mqtt->publish('test/topic', 'Hello from Laravel!');
            
            // Wait a bit for the message
            $mqtt->loop(false, true, 2);
            
            $mqtt->disconnect();
            $this->info("✅ Test completed successfully!");
            
            return 0;
            
        } catch (\Exception $e) {
            $this->error("❌ Connection failed: " . $e->getMessage());
            $this->info("Check if:");
            $this->info("- MQTT broker is running");
            $this->info("- Port 1883 is open");
            $this->info("- Network connectivity is working");
            
            return 1;
        }
    }
}   