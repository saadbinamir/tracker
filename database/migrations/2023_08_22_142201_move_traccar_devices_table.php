<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class MoveTraccarDevicesTable  extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('traccar_devices'))
            return;

        $traccar_db = env('traccar_database', 'gpswox_traccar');

        DB::statement("CREATE TABLE `traccar_devices` LIKE `$traccar_db`.`devices`;");
        DB::statement("INSERT INTO `traccar_devices` SELECT * FROM `$traccar_db`.`devices`;");
        DB::statement("DROP TABLE `$traccar_db`.`devices`");
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        $traccar_db = env('traccar_database', 'gpswox_traccar');

        DB::statement("CREATE TABLE `$traccar_db`.`devices` LIKE `traccar_devices`;");
        DB::statement("INSERT INTO `$traccar_db`.`devices` SELECT * FROM `traccar_devices`;");
        DB::statement("DROP TABLE `traccar_devices`");
    }
}
