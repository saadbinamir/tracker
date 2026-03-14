<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Tobuli\Entities\User;

$user = clone User::where('email', 'admin2@xeontrack.com')->first();
echo "User Group: " . $user->group_id . "\n";
echo "Available Maps (user): " . print_r($user->available_maps, true) . "\n";

$main = settings('main_settings');
echo "Main Settings maps: " . print_r($main['available_maps'] ?? [], true) . "\n";

// Let's force update the maps for the user and settings just in case
if (!isset($main['available_maps']) || empty($main['available_maps'])) {
    $main['available_maps'] = [1, 2, 3, 4, 5, 6, 7];
    settings('main_settings', $main);
    echo "Fixed main_settings maps.\n";
}

$user->available_maps = [1, 2, 3, 4, 5, 6, 7];
$user->save();
echo "Fixed user maps.\n";

echo "Done.\n";
