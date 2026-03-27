<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tobuli\Entities\Alert;
use Tobuli\Entities\Device;
use Tobuli\Entities\TraccarPosition;
use Tobuli\Helpers\Alerts\Check\Checker;
use Tobuli\Services\EventWriteService;

class SyncTraccarData extends Command
{
    protected $signature = 'traccar:sync {--daemon : Run continuously instead of once}';
    protected $description = 'Sync device and position data from standard Traccar into the web app tables';

    const ONLINE_TIMEOUT_SECONDS = 300;
    const DAEMON_SLEEP_SECONDS = 10;
    const MAX_RUNTIME_SECONDS = 3600;

    private $events = [];
    private $eventWriteService;

    public function handle()
    {
        $this->eventWriteService = new EventWriteService();

        if ($this->option('daemon')) {
            return $this->runDaemon();
        }

        return $this->runOnce();
    }

    private function runDaemon()
    {
        $this->info('Starting traccar:sync daemon (every ' . self::DAEMON_SLEEP_SECONDS . 's)...');
        $startTime = time();

        while (true) {
            try {
                $this->runOnce();
            } catch (\Exception $e) {
                $this->error('Sync error: ' . $e->getMessage());
            }

            if ((time() - $startTime) > self::MAX_RUNTIME_SECONDS) {
                $this->info('Max runtime reached, exiting for restart...');
                return 0;
            }

            sleep(self::DAEMON_SLEEP_SECONDS);
        }
    }

    private function runOnce()
    {
        DB::disableQueryLog();

        $this->syncNewDevicesToTraccar();
        $this->syncPositionData();
        $this->syncRedisConnectivity();
        $this->writeEvents();
    }

    private function syncNewDevicesToTraccar()
    {
        $webDevices = DB::connection('mysql')
            ->table('traccar_devices')
            ->select('uniqueId', 'name')
            ->get();

        foreach ($webDevices as $webDevice) {
            $exists = DB::connection('traccar_mysql')
                ->table('tc_devices')
                ->where('uniqueid', $webDevice->uniqueId)
                ->exists();

            if (!$exists) {
                DB::connection('traccar_mysql')
                    ->table('tc_devices')
                    ->insert([
                        'uniqueid' => $webDevice->uniqueId,
                        'name'     => $webDevice->name,
                    ]);
                $this->info("Registered device {$webDevice->uniqueId} in tc_devices");
            }
        }
    }

    private function syncPositionData()
    {
        $webDevices = DB::connection('mysql')
            ->table('traccar_devices')
            ->select('id', 'uniqueId', 'name', 'latestPosition_id',
                     'engine_on_at', 'engine_off_at', 'engine_changed_at')
            ->get();

        foreach ($webDevices as $webDevice) {
            $tcDevice = DB::connection('traccar_mysql')
                ->table('tc_devices')
                ->where('uniqueid', $webDevice->uniqueId)
                ->first();

            if (!$tcDevice) continue;

            // Get latest position from tc_positions by deviceid 
            $tcPosition = DB::connection('traccar_mysql')
                ->table('tc_positions')
                ->where('deviceid', $tcDevice->id)
                ->orderBy('id', 'desc')
                ->first();

            if (!$tcPosition) continue;

            // Check if we have new data
            $currentServerTime = DB::connection('mysql')
                ->table('traccar_devices')
                ->where('id', $webDevice->id)
                ->value('server_time');

            $dataChanged = ($currentServerTime !== $tcPosition->servertime);

            // Parse attributes for ignition/engine status
            $attributes = json_decode($tcPosition->attributes ?? '{}', true) ?: [];
            $otherXml = $this->jsonAttributesToXml($tcPosition->attributes ?? '{}');

            // Determine engine (ignition) status from position attributes
            $ignitionOn = isset($attributes['ignition']) ? (bool)$attributes['ignition'] : null;

            // Build update data
            $now = date('Y-m-d H:i:s');
            $updateData = [
                'lastValidLatitude'  => $tcPosition->latitude,
                'lastValidLongitude' => $tcPosition->longitude,
                'speed'              => $tcPosition->speed,
                'course'             => $tcPosition->course,
                'altitude'           => $tcPosition->altitude,
                'server_time'        => $tcPosition->servertime,
                'device_time'        => $tcPosition->servertime,
                'time'               => $tcPosition->servertime,
                'protocol'           => $tcPosition->protocol,
                'other'              => $otherXml,
                'updated_at'         => $now,
            ];

            // Update engine timestamps (critical for status dot color)
            if ($ignitionOn === true) {
                $updateData['engine_on_at'] = $tcPosition->servertime;
                // Also set moved_at if speed > 0
                if ($tcPosition->speed > 0) {
                    $updateData['moved_at'] = $tcPosition->servertime;
                }
            } elseif ($ignitionOn === false) {
                $updateData['engine_off_at'] = $tcPosition->servertime;
            }

            // Track engine state changes
            $prevEngineOn = $webDevice->engine_on_at && 
                            strtotime($webDevice->engine_on_at) > strtotime($webDevice->engine_off_at ?: '1970-01-01');
            if ($ignitionOn !== null && $ignitionOn !== $prevEngineOn) {
                $updateData['engine_changed_at'] = $tcPosition->servertime;
            }

            // Update speed tracking
            if ($tcPosition->speed > 0) {
                $updateData['moved_at'] = $tcPosition->servertime;
            } else {
                $updateData['stoped_at'] = $tcPosition->servertime;
            }

            // Write to traccar_devices
            DB::connection('mysql')
                ->table('traccar_devices')
                ->where('id', $webDevice->id)
                ->update($updateData);

            // Sync positions to positions_<id> table
            $posTable = "positions_{$webDevice->id}";
            $this->ensurePositionsTable($posTable);
            $newCount = $this->syncRecentPositions($webDevice, $tcDevice);

            // Check position-based alerts when new data arrives
            if ($dataChanged && $newCount > 0) {
                $this->checkPositionAlerts($webDevice, $otherXml, $tcPosition);
            }
        }
    }

    /**
     * Check position-based alerts (ignition, geofence, overspeed, etc.)
     * Uses TraccarPosition model for compatibility with the sensor/checker system
     */
    private function checkPositionAlerts($webDevice, $otherXml, $tcPosition)
    {
        try {
            $device = Device::with(['traccar', 'sensors'])
                ->find($webDevice->id);

            if (!$device || !$device->traccar) return;

            $alerts = $device
                ->alerts()
                ->withPivot('started_at', 'fired_at', 'silenced_at', 'active_from', 'active_to')
                ->checkByPosition()
                ->active()
                ->with(['user', 'geofences', 'drivers', 'events_custom', 'zones'])
                ->get();

            if ($alerts->isEmpty()) return;

            // Build current position using TraccarPosition model (compatible with sensor system)
            $currentPos = new TraccarPosition([
                'latitude'    => $tcPosition->latitude,
                'longitude'   => $tcPosition->longitude,
                'speed'       => $tcPosition->speed,
                'course'      => $tcPosition->course,
                'altitude'    => $tcPosition->altitude,
                'time'        => $tcPosition->servertime,
                'server_time' => $tcPosition->servertime,
                'device_time' => $tcPosition->servertime,
                'other'       => $otherXml,
                'protocol'    => $tcPosition->protocol,
                'valid'       => $tcPosition->valid ?? 1,
            ]);

            // Get previous position for comparison
            $posTable = "positions_{$webDevice->id}";
            $prevRow = DB::connection('traccar_mysql')
                ->table($posTable)
                ->orderBy('id', 'desc')
                ->skip(1)
                ->first();

            $prevPos = null;
            if ($prevRow) {
                $prevPos = new TraccarPosition([
                    'latitude'    => $prevRow->latitude,
                    'longitude'   => $prevRow->longitude,
                    'speed'       => $prevRow->speed,
                    'course'      => $prevRow->course,
                    'altitude'    => $prevRow->altitude,
                    'time'        => $prevRow->time,
                    'server_time' => $prevRow->server_time,
                    'device_time' => $prevRow->device_time,
                    'other'       => $prevRow->other,
                    'protocol'    => $prevRow->protocol,
                    'valid'       => $prevRow->valid ?? 1,
                ]);
            }

            // Run checker
            $checker = new Checker($device, $alerts);
            $events = $checker->check($currentPos, $prevPos);

            if ($events) {
                $this->events = array_merge($this->events, $events);
                $this->info("Generated " . count($events) . " event(s) for {$webDevice->uniqueId}");
            }
        } catch (\Exception $e) {
            $this->warn("Alert check failed for {$webDevice->uniqueId}: " . $e->getMessage());
        }
    }

    private function writeEvents()
    {
        if (empty($this->events)) return;

        try {
            $this->eventWriteService->write($this->events);
            $this->info("Wrote " . count($this->events) . " events");
        } catch (\Exception $e) {
            $this->warn("Event write failed: " . $e->getMessage());
        }

        $this->events = [];
    }

    private function syncRecentPositions($webDevice, $tcDevice)
    {
        $posTable = "positions_{$webDevice->id}";

        $lastSynced = DB::connection('traccar_mysql')
            ->table($posTable)
            ->orderBy('server_time', 'desc')
            ->value('server_time');

        $query = DB::connection('traccar_mysql')
            ->table('tc_positions')
            ->where('deviceid', $tcDevice->id)
            ->orderBy('id', 'asc')
            ->limit(500);

        if ($lastSynced) {
            $query->where('servertime', '>', $lastSynced);
        }

        $newPositions = $query->get();

        foreach ($newPositions as $pos) {
            $otherXml = $this->jsonAttributesToXml($pos->attributes ?? '{}');

            DB::connection('traccar_mysql')
                ->table($posTable)
                ->insert([
                    'altitude'    => $pos->altitude,
                    'course'      => $pos->course,
                    'latitude'    => $pos->latitude,
                    'longitude'   => $pos->longitude,
                    'other'       => $otherXml,
                    'speed'       => $pos->speed,
                    'time'        => $pos->servertime,
                    'device_time' => $pos->servertime,
                    'server_time' => $pos->servertime,
                    'valid'       => $pos->valid ? 1 : 0,
                    'protocol'    => $pos->protocol,
                    'distance'    => 0,
                ]);
        }

        return $newPositions->count();
    }

    private function syncRedisConnectivity()
    {
        $webDevices = DB::connection('mysql')
            ->table('traccar_devices')
            ->select('uniqueId')
            ->get();

        foreach ($webDevices as $webDevice) {
            $tcDevice = DB::connection('traccar_mysql')
                ->table('tc_devices')
                ->where('uniqueid', $webDevice->uniqueId)
                ->first();

            if (!$tcDevice || !$tcDevice->lastupdate) {
                $this->setDeviceOffline($webDevice->uniqueId);
                continue;
            }

            $lastUpdate = strtotime($tcDevice->lastupdate);
            $isOnline = (time() - $lastUpdate) < self::ONLINE_TIMEOUT_SECONDS;

            if ($isOnline) {
                $this->setDeviceOnline($webDevice->uniqueId);
            } else {
                $this->setDeviceOffline($webDevice->uniqueId);
            }
        }
    }

    private function setDeviceOnline($imei)
    {
        try {
            Redis::connection('process')->set("connected.{$imei}", 1);
        } catch (\Exception $e) {
            try { Redis::set("connected.{$imei}", 1); } catch (\Exception $e2) {}
        }
    }

    private function setDeviceOffline($imei)
    {
        try {
            Redis::connection('process')->del("connected.{$imei}");
        } catch (\Exception $e) {
            try { Redis::del("connected.{$imei}"); } catch (\Exception $e2) {}
        }
    }

    private function ensurePositionsTable($tableName)
    {
        if (Schema::connection('traccar_mysql')->hasTable($tableName)) return;

        Schema::connection('traccar_mysql')->create($tableName, function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->double('altitude')->nullable();
            $table->double('course')->nullable();
            $table->double('latitude')->nullable();
            $table->double('longitude')->nullable();
            $table->text('other')->nullable();
            $table->double('speed')->nullable()->index();
            $table->dateTime('time')->nullable()->index();
            $table->dateTime('device_time')->nullable();
            $table->dateTime('server_time')->nullable()->index();
            $table->text('sensors_values')->nullable();
            $table->tinyInteger('valid')->nullable();
            $table->double('distance')->nullable();
            $table->string('protocol', 20)->nullable();
        });

        $this->info("Created table {$tableName}");
    }

    private function jsonAttributesToXml($jsonStr)
    {
        if (empty($jsonStr)) return '<info></info>';

        $attributes = json_decode($jsonStr, true);
        if (!is_array($attributes) || empty($attributes)) return '<info></info>';

        $xml = '<info>';
        foreach ($attributes as $key => $value) {
            if (is_array($value)) continue;
            if (is_bool($value)) $value = $value ? 'true' : 'false';
            $key = preg_replace('/[^a-zA-Z0-9_]/', '', $key);
            $value = htmlspecialchars((string)$value, ENT_XML1);
            $xml .= "<{$key}>{$value}</{$key}>";
        }
        $xml .= '</info>';

        return $xml;
    }
}
