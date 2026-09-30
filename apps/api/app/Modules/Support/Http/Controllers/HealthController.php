<?php

declare(strict_types=1);

namespace App\Modules\Support\Http\Controllers;

use App\Modules\Support\InternalClients;
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

        /*
         * Component detail names what is broken and how slow it is, which is
         * useful to an operator and useful to somebody probing. It stays on the
         * internal network.
         *
         * This used to test the address with str_starts_with($ip, '172.'),
         * which matches the whole of 172.0.0.0/8 — mostly public address space.
         * The private block is 172.16.0.0/12, and a dotted-string prefix cannot
         * express a /12, so the check now goes through the same range matcher
         * the rate limiter uses (config/security.php).
         */
        return app()->environment('local')
            || InternalClients::matches($request->ip());
    }
}
