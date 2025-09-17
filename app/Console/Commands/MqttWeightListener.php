<?php

namespace App\Console\Commands;

use App\Models\DataTimbangan;
use App\Models\DataTimbanganPerbox;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;

class MqttWeightListener extends Command
{
    protected $signature = 'mqtt:listen-weight';
    protected $description = 'Listen to MQTT weight data from digital scales';

    public function handle()
    {
        $broker = config('mqtt.broker');
        $port = config('mqtt.port');
        $topic = config('mqtt.weight_topic');
        
        $this->info("Starting MQTT Weight Listener...");
        $this->info("Broker: {$broker}:{$port}");
        $this->info("Topic: {$topic}");
        
        try {
            // Create connection settings
            $connectionSettings = new ConnectionSettings();
            $connectionSettings->setKeepAliveInterval(60);
            $connectionSettings->setConnectTimeout(5);
            
            // Create MQTT client
            $mqtt = new MqttClient($broker, $port, 'laravel-weight-' . uniqid());
            
            // Connect to broker
            $this->info("Connecting to MQTT broker...");
            $mqtt->connect($connectionSettings);
            $this->info("✓ Connected successfully!");
            
            // Subscribe to weight topic
            $mqtt->subscribe($topic, function (string $topic, string $message) {
                $this->processWeightData($message);
            }, 0);
            
            $this->info("✓ Subscribed to topic: {$topic}");
            $this->info("🔊 Listening for weight data... (Press Ctrl+C to stop)");
            
            // Keep listening
            $mqtt->loop(true);
            
        } catch (\Exception $e) {
            $this->error("❌ MQTT Error: " . $e->getMessage());
            Log::error('MQTT Weight Listener Error', [
                'error' => $e->getMessage(),
                'broker' => $broker,
                'port' => $port,
                'timestamp' => now()
            ]);
            return 1;
        }
    }

    private function processWeightData($message)
    {
        try {
            $this->info("📊 Received: {$message}");
            
            // Parse weight value (handle different formats)
            $weight = $this->parseWeight($message);
            
            if ($weight === null || $weight <= 0) {
                $this->warn("⚠️  Invalid weight: {$message}");
                return;
            }
            
            // Find active session
            $activeSession = $this->findActiveSession();
            
            if (!$activeSession) {
                $this->warn("⚠️  No active session found for weight: {$weight}g");
                return;
            }
            
            // Save to database
            $success = $this->saveWeight($activeSession, $weight);
            
            if ($success) {
                $this->info("✅ Saved: {$weight}g to batch {$activeSession->batch_number}");
            }
            
        } catch (\Exception $e) {
            $this->error("❌ Error processing: " . $e->getMessage());
            Log::error('MQTT weight processing error', [
                'message' => $message,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    private function parseWeight($message)
    {
        $weight = null;
        $unit = 'GR'; // Default unit
        
        // Try as plain number first (assume KG from your friend's scale)
        if (is_numeric($message)) {
            $weight = floatval($message);
            $unit = 'KG'; // Assume KG since your friend sends in KG
        }
        
        // Try as JSON with unit information
        else {
            try {
                $data = json_decode($message, true);
                if (isset($data['weight'])) {
                    $weight = floatval($data['weight']);
                    $unit = $data['unit'] ?? 'KG'; // Default to KG if no unit specified
                }
            } catch (\Exception $e) {
                // Try to extract weight and unit from string
                // Examples: "1.5kg", "1500g", "Weight: 1.2 KG"
                if (preg_match('/(\d+\.?\d*)\s*(kg|g|gram|grams|kilogram|kilograms)/i', $message, $matches)) {
                    $weight = floatval($matches[1]);
                    $unit = strtoupper($matches[2]);
                    
                    // Normalize unit names
                    if (in_array($unit, ['G', 'GRAM', 'GRAMS'])) {
                        $unit = 'GR';
                    } elseif (in_array($unit, ['KG', 'KILOGRAM', 'KILOGRAMS'])) {
                        $unit = 'KG';
                    }
                }
                // Just extract number if no unit found
                elseif (preg_match('/(\d+\.?\d*)/', $message, $matches)) {
                    $weight = floatval($matches[1]);
                    $unit = 'KG'; // Default to KG from your friend's scale
                }
            }
        }
        
        if ($weight === null || $weight <= 0) {
            return null;
        }
        
        // Convert to grams
        return $this->convertToGrams($weight, $unit);
    }
    
    private function convertToGrams($weight, $unit)
    {
        switch (strtoupper($unit)) {
            case 'KG':
            case 'KILOGRAM':
            case 'KILOGRAMS':
                return $weight * 1000; // Convert KG to grams
            case 'GR':
            case 'G':
            case 'GRAM':
            case 'GRAMS':
                return $weight; // Already in grams
            case 'LB':
            case 'POUND':
            case 'POUNDS':
                return $weight * 453.592; // Convert pounds to grams
            default:
                // If unit is unknown, assume KG (since your friend sends in KG)
                $this->warn("Unknown unit '{$unit}', assuming KG");
                return $weight * 1000;
        }
    }
    
    private function findActiveSession()
    {
        return DataTimbangan::where('session_status', 'open')
            ->whereNull('ending_counter_pro')
            ->orderBy('created_at', 'desc')
            ->first();
    }
    
    private function saveWeight($session, $weight)
    {
        try {
            DB::beginTransaction();
            
            // Get next box number
            $lastEntry = DataTimbanganPerbox::where('data_timbangan_id', $session->id)
                ->orderBy('box_no', 'desc')
                ->first();
                
            $nextBoxNo = $lastEntry ? $lastEntry->box_no + 1 : 1;
            
            // Create entry
            DataTimbanganPerbox::create([
                'data_timbangan_id' => $session->id,
                'box_no' => $nextBoxNo,
                'weight_perbox' => $weight,
                'category' => 'Finished Good', // Default category
                'weighed_at' => now(),
            ]);
            
            // Update session totals (use your existing method)
            $this->updateSessionTotals($session->id);
            
            DB::commit();
            return true;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("Database error: " . $e->getMessage());
            return false;
        }
    }
    
    private function updateSessionTotals($dataTimbanganId)
    {
        $entries = DataTimbanganPerbox::where('data_timbangan_id', $dataTimbanganId)->get();
        
        $totalWeightAll = $entries->sum('weight_perbox');
        
        $categories = ['Runner', 'Sapuan', 'Purging', 'Defect', 'Finished Good'];
        $updateData = [
            'total_weight_all' => $totalWeightAll,
            'updated_at' => now(),
        ];
        
        foreach ($categories as $category) {
            $categoryEntries = $entries->where('category', $category);
            $categoryKey = strtolower(str_replace(' ', '_', $category));
            
            if ($category === 'Finished Good') {
                $categoryKey = 'fg';
            }
            
            $updateData["total_weight_{$categoryKey}"] = $categoryEntries->sum('weight_perbox');
            $updateData["total_qty_{$categoryKey}"] = $categoryEntries->count();
        }
        
        DataTimbangan::where('id', $dataTimbanganId)->update($updateData);
    }
}