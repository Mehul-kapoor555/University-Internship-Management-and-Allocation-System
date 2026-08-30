<?php
class EngineRunner
{
    private static $ENGINES = [
        'recommendation'      => 'engines/recommendation_engine.py',
        'applicant_ranking'   => 'engines/applicant_ranking_engine.py',
        'matching'            => 'engines/matching_engine.py',
        'stability_verifier'  => 'engines/stability_verifier.py',
    ];

    private static function pythonCmd(): string
    {
        if (defined('PYTHON_CMD')) {
            return PYTHON_CMD;
        }
        return (stripos(PHP_OS, 'WIN') === 0) ? 'python' : 'python3';
    }

    public static function run(string $engineName, array $args): array
    {
        if (!isset(self::$ENGINES[$engineName])) {
            return ['success' => false, 'data' => null,
                    'error' => "Unknown engine: $engineName", 'duration_ms' => 0];
        }

        $scriptPath = __DIR__ . '/../' . self::$ENGINES[$engineName];
        if (!file_exists($scriptPath)) {
            return ['success' => false, 'data' => null,
                    'error' => "Engine script not found: $scriptPath", 'duration_ms' => 0];
        }

        $cmdParts = [self::pythonCmd(), escapeshellarg($scriptPath)];
        foreach ($args as $key => $value) {
            if (!preg_match('/^[a-z0-9_-]+$/i', $key)) {
                return ['success' => false, 'data' => null,
                        'error' => "Invalid argument name: $key", 'duration_ms' => 0];
            }
            $cmdParts[] = '--' . $key;
            $cmdParts[] = escapeshellarg((string)$value);
        }
        $cmd = implode(' ', $cmdParts) . ' 2>&1';

        $started = microtime(true);
        $output = shell_exec($cmd);
        $durationMs = (int)round((microtime(true) - $started) * 1000);

        if ($output === null || $output === '') {
            return ['success' => false, 'data' => null,
                    'error' => 'Engine returned no output', 'duration_ms' => $durationMs];
        }

        $lines = array_filter(array_map('trim', explode("\n", trim($output))));
        $jsonLine = end($lines);
        $decoded = json_decode($jsonLine, true);

        if ($decoded === null) {
            return ['success' => false, 'data' => $output,
                    'error' => 'Engine output was not JSON: ' . substr($output, 0, 200),
                    'duration_ms' => $durationMs];
        }

        if (isset($decoded['error'])) {
            return ['success' => false, 'data' => $decoded,
                    'error' => $decoded['error'], 'duration_ms' => $durationMs];
        }

        return ['success' => true, 'data' => $decoded, 'error' => null,
                'duration_ms' => $durationMs];
    }
}