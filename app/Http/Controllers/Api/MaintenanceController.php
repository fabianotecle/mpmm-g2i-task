<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class MaintenanceController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $this->authenticate($request);

        $data = $request->validate([
            'action' => ['required', 'string', 'in:database_check,migrate_status,migrate,clear_cache,deploy'],
        ]);

        return match ($data['action']) {
            'database_check' => $this->databaseCheck(),
            'migrate_status' => $this->artisan('migrate:status'),
            'migrate' => $this->artisan('migrate', ['--force' => true]),
            'clear_cache' => $this->clearCache(),
            'deploy' => $this->deploy(),
        };
    }

    private function authenticate(Request $request): void
    {
        $expected = (string) config('mpmm.maintenance.token');
        $provided = (string) $request->bearerToken();

        abort_if(strlen($expected) < 32, 503, 'Maintenance endpoint is not configured.');
        abort_unless(hash_equals($expected, $provided), 403, 'Forbidden.');
    }

    private function databaseCheck(): JsonResponse
    {
        DB::connection()->getPdo();

        return response()->json([
            'ok' => true,
            'action' => 'database_check',
            'connection' => DB::connection()->getName(),
        ]);
    }

    private function artisan(string $command, array $arguments = []): JsonResponse
    {
        $exitCode = Artisan::call($command, $arguments);

        return response()->json([
            'ok' => $exitCode === 0,
            'action' => $command,
            'exit_code' => $exitCode,
            'output' => trim(Artisan::output()),
        ], $exitCode === 0 ? 200 : 500);
    }

    private function clearCache(): JsonResponse
    {
        $results = [];

        foreach (['config:clear', 'route:clear', 'view:clear', 'cache:clear', 'event:clear'] as $command) {
            $exitCode = Artisan::call($command);
            $results[$command] = [
                'exit_code' => $exitCode,
                'output' => trim(Artisan::output()),
            ];

            if ($exitCode !== 0) {
                return response()->json(['ok' => false, 'action' => 'clear_cache', 'results' => $results], 500);
            }
        }

        return response()->json(['ok' => true, 'action' => 'clear_cache', 'results' => $results]);
    }

    private function deploy(): JsonResponse
    {
        $results = [];

        foreach ([
            ['config:clear', []],
            ['route:clear', []],
            ['view:clear', []],
            ['cache:clear', []],
            ['migrate', ['--force' => true]],
            ['optimize:clear', []],
        ] as [$command, $arguments]) {
            $exitCode = Artisan::call($command, $arguments);
            $results[$command] = [
                'exit_code' => $exitCode,
                'output' => trim(Artisan::output()),
            ];

            if ($exitCode !== 0) {
                return response()->json(['ok' => false, 'action' => 'deploy', 'results' => $results], 500);
            }
        }

        return response()->json(['ok' => true, 'action' => 'deploy', 'results' => $results]);
    }
}
