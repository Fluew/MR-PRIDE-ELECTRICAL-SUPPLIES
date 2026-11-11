<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
            'storage' => $this->checkStorage(),
        ];

        $healthy = ! in_array(false, array_column($checks, 'ok'), true);

        return response()->json([
            'status' => $healthy ? 'healthy' : 'degraded',
            'checks' => $checks,
            'timestamp' => now()->toIso8601String(),
        ], $healthy ? 200 : 503);
    }

    private function checkDatabase(): array
    {
        try {
            $start = microtime(true);
            DB::select('SELECT 1');
            $ms = round((microtime(true) - $start) * 1000, 1);

            return ['ok' => true, 'latency_ms' => $ms];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Connection failed'];
        }
    }

    private function checkCache(): array
    {
        try {
            $key = 'health_check_' . uniqid();
            Cache::put($key, true, 10);
            $result = Cache::get($key);
            Cache::forget($key);

            return ['ok' => $result === true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Cache unavailable'];
        }
    }

    private function checkStorage(): array
    {
        $writable = is_writable(storage_path('logs'));

        return ['ok' => $writable];
    }
}
