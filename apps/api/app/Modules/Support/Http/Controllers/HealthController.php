<?php

declare(strict_types=1);

namespace App\Modules\Support\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * GET /api/v1/health
 *
 * Public response: service status only.
 * ?detail=1 from the internal network adds component checks (docs/06 §22).
 */
final class HealthController
{
    public function __invoke(Request $request): JsonResponse
    {
        $checks = [
            'central_database' => $this->checkCentralDatabase(),
        ];

        $healthy = ! in_array('error', array_column($checks, 'status'), true);

        $payload = [
            'status' => $healthy ? 'ok' : 'degraded',
            'service' => config('app.name'),
            'version' => (string) config('app.version', 'dev'),
            'time' => now()->toIso8601String(),
        ];

        if ($this->detailAllowed($request)) {
            $payload['checks'] = $checks;
        }

        return response()->json($payload, $healthy ? 200 : 503);
    }

    /**
     * @return array{status: string, latency_ms?: float, message?: string}
     */
    private function checkCentralDatabase(): array
    {
        $startedAt = microtime(true);

        try {
            DB::connection('central')->select('select 1');

            return [
                'status' => 'ok',
                'latency_ms' => round((microtime(true) - $startedAt) * 1000, 1),
            ];
        } catch (Throwable $e) {
            report($e);

            return [
                'status' => 'error',
                'message' => 'central database unreachable',
            ];
        }
    }

    private function detailAllowed(Request $request): bool
    {
        if ($request->query('detail') !== '1') {
            return false;
        }

        $ip = (string) $request->ip();

        return app()->environment('local')
            || str_starts_with($ip, '10.')
            || str_starts_with($ip, '172.')
            || str_starts_with($ip, '192.168.')
            || $ip === '127.0.0.1';
    }
}
