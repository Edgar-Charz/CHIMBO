<?php

/**
 * GET /api/v1/health — confirms the API is running and can reach the database.
 * Used by the mobile app (first connection test) and later by uptime monitoring.
 */
class HealthController
{
    public function show(Request $request): Response
    {
        try {
            Database::instance()->fetchValue('SELECT 1');
            $database = 'ok';
        } catch (Throwable $e) {
            Logger::exception($e, ['check' => 'health.database']);
            $database = 'down';
        }

        $data = [
            'status'   => $database === 'ok' ? 'ok' : 'degraded',
            'app'      => Env::get('APP_NAME', 'CHIMBO'),
            'version'  => 'v1',
            'database' => $database,
            'time'     => gmdate('c'),
        ];

        return Response::success($data, $database === 'ok' ? 200 : 503);
    }
}
