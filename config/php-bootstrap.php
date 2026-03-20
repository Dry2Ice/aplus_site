<?php

/**
 * Centralized PHP runtime hardening for public endpoints.
 *
 * - Disables error display in HTTP responses
 * - Logs warnings/exceptions/fatal errors into data/php-errors.log
 * - Uses JSON-line format to simplify machine parsing and alerting
 */

if (!function_exists('aplus_error_log_path')) {
    function aplus_error_log_path(): string {
        return dirname(__DIR__) . '/data/php-errors.log';
    }
}

if (!function_exists('aplus_write_error_log')) {
    function aplus_write_error_log(array $record): void {
        $payload = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($payload) || $payload === '') {
            return;
        }
        @file_put_contents(aplus_error_log_path(), $payload . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

if (!function_exists('aplus_init_error_logging')) {
    function aplus_init_error_logging(string $channel = 'web'): void {
        ini_set('display_errors', '0');
        ini_set('display_startup_errors', '0');
        error_reporting(E_ALL);

        set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0) use ($channel): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }

            aplus_write_error_log([
                'at' => gmdate('c'),
                'channel' => $channel,
                'type' => 'warning',
                'severity' => $severity,
                'message' => $message,
                'file' => $file,
                'line' => $line,
            ]);

            return true;
        });

        set_exception_handler(static function (Throwable $exception) use ($channel): void {
            aplus_write_error_log([
                'at' => gmdate('c'),
                'channel' => $channel,
                'type' => 'exception',
                'class' => get_class($exception),
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ]);

            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json; charset=UTF-8');
            }

            echo json_encode([
                'success' => false,
                'message' => 'Internal server error',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit();
        });

        register_shutdown_function(static function () use ($channel): void {
            $lastError = error_get_last();
            if (!is_array($lastError)) {
                return;
            }

            if (!in_array((int)$lastError['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }

            aplus_write_error_log([
                'at' => gmdate('c'),
                'channel' => $channel,
                'type' => 'fatal',
                'severity' => (int)$lastError['type'],
                'message' => (string)($lastError['message'] ?? ''),
                'file' => (string)($lastError['file'] ?? ''),
                'line' => (int)($lastError['line'] ?? 0),
            ]);
        });
    }
}

