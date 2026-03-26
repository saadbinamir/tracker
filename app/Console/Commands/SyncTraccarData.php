<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tobuli\Entities\Alert;
use Tobuli\Entities\Device;
use Tobuli\Helpers\Alerts\Check\Checker;
use Tobuli\Services\EventWriteService;

class SyncTraccarData extends Command
{
    protected $signature = 'traccar:sync {--daemon : Run continuously instead of once}';
    protected $description = 'Sync device and position data from standard Traccar (tc_devices/tc_positions) into the web app tables';

    // How many seconds of inactivity before a device is considered offline
    const ONLINE_TIMEOUT_SECONDS = 300;

    // How often to sync in daemon mode (seconds)
    const DAEMON_SLEEP_SECONDS = 10;

    // Max runtime for daemon mode (seconds) - prevents memory leaks, supervisor will restart
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

            // Exit after max runtime to prevent memory leaks (supervisor will restart)
            if ((time() - $startTime) > self::MAX_RUNTIME_SECONDS) {
                $this->info('Max runtime reached, exiting for restart...');
                return 0;
            }

            sleep(self::DAEMON_SLEEP_SECONDS);
        }
    }

    private function runOnce()
    {
        $this->syncNewDevicesToTraccar();
        $this->syncPositionData();
        $this->syncRedisConnectivity();

        // Write any events that were generated during position sync
        $this->writeEvents();
    }

    /**
     * Step 1: Register any web-app devices that don't exist in tc_devices yet
     */
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

                $this->info("Registered device {$webDevice->uniqueId} ({$webDevice->name}) in tc_devices");
            }
        }
    }

    /**
     * Step 2: Sync latest position data from tc_positions → traccar_devices + positions_<id>
     */
    private function syncPositionData()
    {
        // Get all web-app devices that have a matching tc_devices entry
        $webDevices = DB::connection('mysql')
            ->table('traccar_devices')
            ->select('id', 'uniqueId', 'name', 'latestPosition_id')
            ->get();

        foreach ($webDevices as $webDevice) {
            // Find matching tc_device
            $tcDevice = DB::connection('traccar_mysql')
                ->table('tc_devices')
                ->where('uniqueid', $webDevice->uniqueId)
                ->first();

            if (!$tcDevice) {
                continue;
            }

            // Get the ACTUAL latest position from tc_positions by deviceid
            // (don't rely on tc_devices.positionid — it's not always updated)
            $tcPosition = DB::connection('traccar_mysql')
                ->table('tc_positions')
                ->where('deviceid', $tcDevice->id)
                ->orderBy('id', 'desc')
                ->first();

            if (!$tcPosition) {
                continue;
            }

            // Convert Traccar JSON attributes to the XML format the web app expects
            $otherXml = $this->jsonAttributesToXml($tcPosition->attributes ?? '{}');

            // Check if data actually changed (avoid unnecessary updates)
            $currentServerTime = DB::connection('mysql')
                ->table('traccar_devices')
                ->where('id', $webDevice->id)
                ->value('server_time');

            $dataChanged = ($currentServerTime !== $tcPosition->servertime);

            // Update traccar_devices with the latest position data
            // Use servertime for time/device_time because device GPS clocks are unreliable
            DB::connection('mysql')
                ->table('traccar_devices')
                ->where('id', $webDevice->id)
                ->update([
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
                ]);

            // Ensure the positions_<id> table exists and sync recent positions
            $posTable = "positions_{$webDevice->id}";
            $this->ensurePositionsTable($posTable);
            $newCount = $this->syncRecentPositions($webDevice, $tcDevice);

            // Check position-based alerts (ignition, geofence, speed, etc.) when data changes
            if ($dataChanged && $newCount > 0) {
                $this->checkPositionAlerts($webDevice, $otherXml, $tcPosition);
            }
        }
    }

    /**
     * Check position-based alerts (ignition on/off, geofence, overspeed, etc.)
     */
    private function checkPositionAlerts($webDevice, $otherXml, $tcPosition)
    {
        try {
            // Load the Device model with its alerts and sensors
            $device = Device::with(['traccar', 'sensors'])
                ->find($webDevice->id);

            if (!$device || !$device->traccar) {
                return;
            }

            // Get position-based alerts for this device
            $alerts = $device
                ->alerts()
                ->withPivot('started_at', 'fired_at', 'silenced_at', 'active_from', 'active_to')
                ->checkByPosition()
                ->active()
                ->with(['user', 'geofences', 'drivers', 'events_custom', 'zones'])
                ->get();

            if ($alerts->isEmpty()) {
                return;
            }

            // Create a position-like object for the Checker
            $positionClass = 'Tobuli\Entities\TraccarPosition';
            if (!class_exists($positionClass)) {
                $positionClass = 'App\Console\Position';
            }

            // Build current position
            $currentPos = new \stdClass();
            $currentPos->latitude = $tcPosition->latitude;
            $currentPos->longitude = $tcPosition->longitude;
            $currentPos->speed = $tcPosition->speed;
            $currentPos->course = $tcPosition->course;
            $currentPos->altitude = $tcPosition->altitude;
            $currentPos->time = $tcPosition->fixtime;
            $currentPos->server_time = $tcPosition->servertime;
            $currentPos->device_time = $tcPosition->devicetime;
            $currentPos->other = $otherXml;
            $currentPos->protocol = $tcPosition->protocol;
            $currentPos->valid = $tcPosition->valid ?? 1;

            // Get previous position for comparison
            $posTable = "positions_{$webDevice->id}";
            $prevRow = DB::connection('traccar_mysql')
                ->table($posTable)
                ->orderBy('id', 'desc')
                ->skip(1)
                ->first();

            $prevPos = null;
            if ($prevRow) {
                $prevPos = new \stdClass();
                $prevPos->latitude = $prevRow->latitude;
                $prevPos->longitude = $prevRow->longitude;
                $prevPos->speed = $prevRow->speed;
                $prevPos->course = $prevRow->course;
                $prevPos->altitude = $prevRow->altitude;
                $prevPos->time = $prevRow->time;
                $prevPos->server_time = $prevRow->server_time;
                $prevPos->device_time = $prevRow->device_time;
                $prevPos->other = $prevRow->other;
                $prevPos->protocol = $prevRow->protocol;
                $prevPos->valid = $prevRow->valid ?? 1;
            }

            // Run the alert checker
            $checker = new Checker($device, $alerts);
            $events = $checker->check($currentPos, $prevPos);

            if ($events) {
                $this->events = array_merge($this->events, $events);
            }
        } catch (\Exception $e) {
            $this->warn("Alert check failed for {$webDevice->uniqueId}: " . $e->getMessage());
        }
    }

    /**
     * Write accumulated events
     */
    private function writeEvents()
    {
        if (empty($this->events)) {
            return;
        }

        try {
            $this->eventWriteService->write($this->events);
            $count = count($this->events);
            $this->info("Wrote {$count} events");
        } catch (\Exception $e) {
            $this->warn("Event write failed: " . $e->getMessage());
        }

        $this->events = [];
    }

    /**
     * Sync recent tc_positions that haven't been synced yet
     * Returns the count of new positions synced
     */
    private function syncRecentPositions($webDevice, $tcDevice)
    {
        $posTable = "positions_{$webDevice->id}";

        // Get the most recent server_time we already have
        $lastSynced = DB::connection('traccar_mysql')
            ->table($posTable)
            ->orderBy('server_time', 'desc')
            ->value('server_time');

        // Get new positions from tc_positions since last sync
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

    /**
     * Step 3: Set Redis connectivity keys based on tc_devices.lastupdate
     */
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
            try {
                Redis::set("connected.{$imei}", 1);
            } catch (\Exception $e2) {
                // Silently ignore
            }
        }
    }

    private function setDeviceOffline($imei)
    {
        try {
            Redis::connection('process')->del("connected.{$imei}");
        } catch (\Exception $e) {
            try {
                Redis::del("connected.{$imei}");
            } catch (\Exception $e2) {
                // Silently ignore
            }
        }
    }

    /**
     * Create a positions_<id> table if it doesn't exist
     */
    private function ensurePositionsTable($tableName)
    {
        if (Schema::connection('traccar_mysql')->hasTable($tableName)) {
            return;
        }

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

    /**
     * Convert Traccar's JSON attributes string to the XML format the web app expects
     */
    private function jsonAttributesToXml($jsonStr)
    {
        if (empty($jsonStr)) {
            return '<info></info>';
        }

        $attributes = json_decode($jsonStr, true);

        if (!is_array($attributes) || empty($attributes)) {
            return '<info></info>';
        }

        $xml = '<info>';
        foreach ($attributes as $key => $value) {
            if (is_array($value)) {
                continue;
            }
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            $key = preg_replace('/[^a-zA-Z0-9_]/', '', $key);
            $value = htmlspecialchars((string)$value, ENT_XML1);
            $xml .= "<{$key}>{$value}</{$key}>";
        }
        $xml .= '</info>';

        return $xml;
    }
}
