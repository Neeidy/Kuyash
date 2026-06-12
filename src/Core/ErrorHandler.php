<?php

declare(strict_types=1);

namespace Kuyash\Core;

use ErrorException;
use Throwable;

/**
 * Central error handling: every Throwable is logged to storage/logs/ and the
 * user only ever sees a generic 500 page — unless app.debug is true, in which
 * case the message and trace are shown (dev only; .env controls the flag).
 * No silent catches anywhere else: let errors bubble up to this handler.
 */
final class ErrorHandler
{
    public function __construct(
        private readonly Config $config,
        private readonly View $view,
        private readonly string $logDir,
    ) {
    }

    /** Install global PHP handlers. Call once during bootstrap. */
    public function register(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', $this->isDebug() ? '1' : '0');
        ini_set('log_errors', '1');

        set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
            if ((error_reporting() & $severity) === 0) {
                return false; // respect @-suppression / error_reporting config
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler(function (Throwable $e): void {
            $this->renderException($e)->send();
        });

        register_shutdown_function(function (): void {
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                $this->renderException(new ErrorException(
                    $error['message'],
                    0,
                    $error['type'],
                    $error['file'],
                    $error['line'],
                ))->send();
            }
        });
    }

    /** Log the throwable, return a safe 500 response (generic unless debug). */
    public function renderException(Throwable $e): Response
    {
        $this->log($e);

        if ($this->isDebug()) {
            $body = '<h1>Unhandled ' . View::e($e::class) . '</h1>'
                . '<p>' . View::e($e->getMessage()) . '</p>'
                . '<p>' . View::e($e->getFile() . ':' . $e->getLine()) . '</p>'
                . '<pre>' . View::e($e->getTraceAsString()) . '</pre>';

            return Response::html($body, 500);
        }

        try {
            return Response::html($this->view->render('errors/500', ['title' => '500 — Server Error']), 500);
        } catch (Throwable) {
            // last resort: template rendering itself failed
            return Response::html('<h1>Server Error</h1><p>Something went wrong. The error has been logged.</p>', 500);
        }
    }

    private function log(Throwable $e): void
    {
        $line = sprintf(
            "[%s] %s: %s in %s:%d\n%s\n\n",
            date('Y-m-d H:i:s'),
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString(),
        );

        // Logging must never throw inside the exception handler — a failed
        // write would replace the original error with a blank fatal. This is
        // the one sanctioned suppression: fall back to the SAPI error log.
        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0750, true);
        }

        $written = @file_put_contents($this->logDir . '/app-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
        if ($written === false) {
            error_log('Kuyash: log write failed; original error follows. ' . $line);
        }
    }

    private function isDebug(): bool
    {
        return $this->config->get('app.debug') === true;
    }
}
