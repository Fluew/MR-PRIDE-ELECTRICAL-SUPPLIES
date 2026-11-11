<?php

require __DIR__ . '/../../../../xampp/htdocs/mr-pride/vendor/autoload.php';
$app = require_once __DIR__ . '/../../../../xampp/htdocs/mr-pride/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\Team;

$user = User::where('email', 'admin@example.com')->first();
echo "User: " . ($user ? $user->name . " (ID: {$user->id}, current_team_id: {$user->current_team_id})" : "NOT FOUND") . "\n";

if ($user) {
    echo "Teams user belongs to:\n";
    foreach ($user->teams as $t) {
        echo " - Team: {$t->name} (slug: {$t->slug}, id: {$t->id})\n";
    }
    echo "Current Team: " . ($user->currentTeam ? $user->currentTeam->name . " (slug: " . $user->currentTeam->slug . ")" : "NULL") . "\n";
}

$allTeams = Team::all();
echo "\nAll Teams in DB:\n";
foreach ($allTeams as $t) {
    echo " - Team ID: {$t->id}, Name: {$t->name}, User ID (Owner): {$t->user_id}, Slug: {$t->slug}\n";
}
