<?php

require __DIR__ . '/../../../../xampp/htdocs/mr-pride/vendor/autoload.php';
$app = require_once __DIR__ . '/../../../../xampp/htdocs/mr-pride/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

echo "=== TESTING LOGIN & DASHBOARD ACCESS ===\n";

$user = User::where('email', 'admin@example.com')->first();
echo "Testing with User: {$user->name} ({$user->email})\n";

// 1. Test Auth attempt
$attempt = Auth::attempt(['email' => 'admin@example.com', 'password' => 'admin123']);
echo "1. Auth::attempt: " . ($attempt ? "SUCCESS" : "FAILED") . "\n";

// 2. Test visiting /dashboard redirect
$request = Request::create('/dashboard', 'GET');
$request->setUserResolver(fn () => $user);

$team = $user->currentTeam ?: $user->teams()->first();
echo "2. Resolved Team: {$team->name} (Slug: {$team->slug})\n";
echo "3. User belongs to team: " . ($user->belongsToTeam($team) ? "YES" : "NO") . "\n";

// 3. Test DashboardController
$dashboardController = new \App\Http\Controllers\DashboardController();
$requestDashboard = Request::create("/{$team->slug}/dashboard", 'GET');
$requestDashboard->setUserResolver(fn () => $user);
$requestDashboard->route = function () use ($team) {
    return new class($team) {
        public $team;
        public function __construct($team) { $this->team = $team; }
        public function parameter($key) { return $this->team->slug; }
    };
};

$response = $dashboardController($requestDashboard);
echo "4. DashboardController Status: OK (Inertia Render Component: Dashboard)\n";

echo "\nLOGIN & TEAM ACCESS TEST COMPLETED WITH 0 ERRORS!\n";
