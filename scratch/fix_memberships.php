<?php

require __DIR__ . '/../../../../xampp/htdocs/mr-pride/vendor/autoload.php';
$app = require_once __DIR__ . '/../../../../xampp/htdocs/mr-pride/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\Team;
use App\Models\Membership;
use App\Enums\TeamRole;

$users = User::all();
$teams = Team::all();

echo "Ensuring all users have memberships in teams...\n";

foreach ($users as $user) {
    foreach ($teams as $team) {
        Membership::firstOrCreate(
            ['user_id' => $user->id, 'team_id' => $team->id],
            ['role' => TeamRole::Owner]
        );
    }
    
    // Set current team
    $firstTeam = $teams->first();
    if ($firstTeam) {
        $user->current_team_id = $firstTeam->id;
        $user->save();
    }
    
    echo "User {$user->email} attached to all " . $teams->count() . " teams as Owner.\n";
}

echo "Done!\n";
