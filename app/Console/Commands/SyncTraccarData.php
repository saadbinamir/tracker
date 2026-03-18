<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

class SyncTraccarData extends Command
{
    protected $signature = 'traccar:sync';
    protected $description = 'Sync device and position data from standard Traccar (tc_devices/tc_positions) into the web app tables (traccar_devices/positions_<id>)';

    // How many seconds of inactivity before a device is considered offline
    const ONLINE_TIMEOUT_SECONDS = 300;

    public function handle()
    {
        $this->syncNewDevicesToTraccar();
        $this->syncPositionData();
        $this->syncRedisConnectivity();

        $this->info('Traccar sync completed.');
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

            if (!$tcDevice || !$tcDevice->positionid) {
                continue;
            }

            // Get the latest position from tc_positions
            $tcPosition = DB::connection('traccar_mysql')
                ->table('tc_positions')
                ->where('id', $tcDevice->positionid)
                ->first();

            if (!$tcPosition) {
                continue;
            }

            // Convert Traccar JSON attributes to the XML format the web app expects
            $otherXml = $this->jsonAttributesToXml($tcPosition->attributes ?? '{}');

            // Update traccar_devices with the latest position data
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
                    'device_time'        => $tcPosition->devicetime,
                    'time'               => $tcPosition->fixtime,
                    'protocol'           => $tcPosition->protocol,
                    'other'              => $otherXml,
                ]);

            // Ensure the positions_<id> table exists and insert the position
            $posTable = "positions_{$webDevice->id}";
            $this->ensurePositionsTable($posTable);

            // Only insert if this position is new (check by time to avoid duplicates)
            $lastPos = DB::connection('traccar_mysql')
                ->table($posTable)
                ->orderBy('id', 'desc')
                ->first();

            if (!$lastPos || $lastPos->server_time !== $tcPosition->servertime) {
                DB::connection('traccar_mysql')
                    ->table($posTable)
                    ->insert([
                        'altitude'    => $tcPosition->altitude,
                        'course'      => $tcPosition->course,
                        'latitude'    => $tcPosition->latitude,
                        'longitude'   => $tcPosition->longitude,
                        'other'       => $otherXml,
                        'speed'       => $tcPosition->speed,
                        'time'        => $tcPosition->fixtime,
                        'device_time' => $tcPosition->devicetime,
                        'server_time' => $tcPosition->servertime,
                        'valid'       => $tcPosition->valid ? 1 : 0,
                        'protocol'    => $tcPosition->protocol,
                        'distance'    => 0,
                    ]);
            }

            // Sync recent positions (last N) for history from tc_positions
            $this->syncRecentPositions($webDevice, $tcDevice);
        }
    }

    /**
     * Sync recent tc_positions that haven't been synced yet
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
                    'time'        => $pos->fixtime,
                    'device_time' => $pos->devicetime,
                    'server_time' => $pos->servertime,
                    'valid'       => $pos->valid ? 1 : 0,
                    'protocol'    => $pos->protocol,
                    'distance'    => 0,
                ]);
        }

        if ($newPositions->isNotEmpty()) {
            $this->info("Synced {$newPositions->count()} positions for {$webDevice->uniqueId}");
        }
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
            // Fallback to default connection
            try {
                Redis::set("connected.{$imei}", 1);
            } catch (\Exception $e2) {
                $this->warn("Could not set Redis key for {$imei}: {$e2->getMessage()}");
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
     * Create a positions_<id> table if it doesn't exist (using the traccar_mysql connection)
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
     * Input:  {"batteryLevel":98,"distance":0.0,"totalDistance":123.45,...}
     * Output: <info><batteryLevel>98</batteryLevel><distance>0.0</distance>...</info>
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
