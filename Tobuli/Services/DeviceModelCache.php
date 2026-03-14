<?php

namespace Tobuli\Services;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;
use Tobuli\Entities\Device;

class DeviceModelCache
{
    public const PREFIX = 'model.';

    private static Connection $redis;

    public static function keys(): array
    {
        try {
            return self::getRedis()->keys(self::PREFIX . '*');
        } catch (\Exception $e) {
            return [];
        }
    }

    public static function setDevice(Device $device, ?string $oldImei = null)
    {
        try {
            return self::getRedis()->pipeline(function ($pipe) use ($device, $oldImei) {
                if ($oldImei) {
                    $pipe->del(self::PREFIX . $oldImei);
                }

                if ($device->model_id) {
                    $pipe->set(self::PREFIX . $device->imei, $device->model->model);
                } else {
                    $pipe->del(self::PREFIX . $device->imei);
                }
            });
        } catch (\Exception $e) {
            return null;
        }
    }

    public static function deleteDevice(Device $device)
    {
        try {
            return self::getRedis()->del(self::PREFIX . $device->imei);
        } catch (\Exception $e) {
            return null;
        }
    }

    public static function reload()
    {
        $keys = self::keys();

        return self::getRedis()->pipeline(function ($pipe) use ($keys) {
            foreach ($keys as $key) {
                $pipe->del($key);
            }

            Device::whereNotNull('model_id')
                ->with(['model'])
                ->select(['model_id', 'imei'])
                ->chunk(1000, function ($devices) use ($pipe) {
                    /** @var Device $device */
                    foreach ($devices as $device) {
                        $pipe->set(self::PREFIX . $device->imei, $device->model->model);
                    }
                });
        });
    }

    private static function getRedis(): Connection
    {
        return self::$redis ?? self::$redis = Redis::connection('process');
    }
}