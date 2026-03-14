<?php

namespace Database\Seeders;

use File;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Seeder;
use Tobuli\Repositories\DeviceIcon\DeviceIconRepositoryInterface as DeviceIcon;

class DeviceIconsTableSeeder extends Seeder {
    /**
     * @var DeviceIcon
     */
    private $DeviceIcon;

    public function __construct(DeviceIcon $DeviceIcon)
    {
        $this->DeviceIcon = $DeviceIcon;
    }

	public function run()
	{
        # Icons
        $folder = base_path('images/device_icons/v2');

        if( ! File::isDirectory($folder))
            $folder = public_path('images/device_icons/v2');

        $files = File::allFiles($folder);

        foreach ($files as $file)
        {
            if (!is_object($file) || empty($file->getFilename()))
                continue;

            list($width, $height) = getimagesize($file);
            if (!$width || !$height)
                continue;

            $path = 'images/device_icons/v2/' . $file->getFilename();

            if ($this->DeviceIcon->findWhere(['path' => $path]))
                continue;

            $this->DeviceIcon->create([
                'path'   => $path,
                'order'  => 3,
                'width'  => $width,
                'height' => $height,
                'type'   => 'icon'
            ]);
        }

        # Rotating icons
        $folder = base_path('images/device_icons/rotating');

        if( ! File::isDirectory($folder))
            $folder = public_path('images/device_icons/rotating');

        $files = File::allFiles($folder);

        foreach ($files as $file)
        {
            if (!is_object($file) || empty($file->getFilename()))
                continue;

            list($width, $height) = getimagesize($file);
            if (!$width || !$height)
                continue;

            $path = 'images/device_icons/rotating/' . $file->getFilename();

            if ($this->DeviceIcon->findWhere(['path' => $path]))
                continue;

            $this->DeviceIcon->create([
                'path'   => $path,
                'order'  => 3,
                'width'  => $width,
                'height' => $height,
                'type'   => 'rotating'
            ]);
        }

        $web_db = env('web_database', 'gpswox_web');

        DB::statement("DELETE FROM `$web_db`.`device_icons` WHERE `device_icons`.`path` IN ('images/arrow-ack.png', 'assets/images/arrow-ack.png');");
        DB::statement("INSERT INTO `$web_db`.`device_icons` (`id`, `order`, `width`, `height`, `path`, `type`) VALUES ('0', '1', '25', '33', 'assets/images/arrow-ack.png', 'arrow');");
        DB::statement("UPDATE `$web_db`.`device_icons` SET `id` = '0' WHERE `device_icons`.`path` = 'assets/images/arrow-ack.png';");
	}

}