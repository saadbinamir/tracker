<?php

namespace Tobuli\Services;

use CustomFacades\ModalHelpers\SensorModalHelper;
use Tobuli\Entities\Device;
use Tobuli\Entities\SensorGroupSensor;
use Tobuli\Entities\User;
use Tobuli\Sensors\SensorsManager;

class DeviceSensorsService
{
    public function __construct()
    {

    }

    /**
     * @param Device $device
     * @param User $user
     * @param int $sensor_group_id
     */
    public function addSensorGroup(Device $device, User $user, int $sensor_group_id) {
        $group_sensors = SensorGroupSensor::where(['group_id' => $sensor_group_id])->get();

        if (empty($group_sensors)) {
            return;
        }

        foreach ($group_sensors as $sensor) {
            $sensor = $sensor->toArray();

            $this->addSensor($device, $user, $sensor);
        }
    }

    /**
     * @param Device $device
     * @param User $user
     * @param array $data
     */
    public function addSensor(Device $device, User $user, array $data)
    {
        //tmp
        if ( ! ($data['show_in_popup'] ?? null)) {
            unset($data['show_in_popup']);
        }

        if (empty($data['shown_value_by'])) {
            $sensorsManager = new SensorsManager();
            $sensorType = $sensorsManager->resolveType($data['type']);
            if ($sensorType && $showTypes = $sensorType::getShowTypes()) {
                $data['shown_value_by'] = array_key_first($showTypes);
            }
        }

        SensorModalHelper::setData(array_merge([
            'user_id' => $user->id,
            'device_id' => $device->id,
            'sensor_type' => $data['type'],
            'sensor_name' => $data['name'],
        ], $data));

        SensorModalHelper::create();
    }
}
