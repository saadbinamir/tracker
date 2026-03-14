<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class MoveUnregisteredDevicesLogTable  extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('unregistered_devices_log'))
            return;

        $traccar_db = env('traccar_database', 'gpswox_traccar');

        DB::statement("CREATE TABLE `unregistered_devices_log` LIKE `$traccar_db`.`unregistered_devices_log`;");
        DB::statement("INSERT INTO `unregistered_devices_log` SELECT * FROM `$traccar_db`.`unregistered_devices_log`;");
        DB::statement("DROP TABLE `$traccar_db`.`unregistered_devices_log`");
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        $traccar_db = env('traccar_database', 'gpswox_traccar');

        DB::statement("CREATE TABLE `$traccar_db`.`unregistered_devices_log` LIKE `unregistered_devices_log`;");
        DB::statement("INSERT INTO `$traccar_db`.`unregistered_devices_log` SELECT * FROM `unregistered_devices_log`;");
        DB::statement("DROP TABLE `unregistered_devices_log`");
    }
}
