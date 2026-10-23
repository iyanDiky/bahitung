<?php

use App\Exceptions\FinancialException;
use App\Exceptions\IdempotencyConflictException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->redirectGuestsTo(fn (Request $request): ?string => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }
            $status = match (true) {
                $exception instanceof ValidationException => 422,
                $exception instanceof AuthenticationException => 401,
                $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
                default => 500,
            };
            $code = match ($status) {
                401 => 'UNAUTHENTICATED', 403 => 'FORBIDDEN', 404 => 'RESOURCE_NOT_FOUND', 409 => 'STATE_CONFLICT', 422 => 'VALIDATION_ERROR', 429 => 'RATE_LIMITED', default => 'HTTP_'.$status
            };
            $message = match ($status) {
                401 => 'Login diperlukan atau kredensial tidak valid.', 403 => 'Anda tidak memiliki izin untuk tindakan ini.', 404 => 'Sumber daya tidak ditemukan.', 409 => 'Status data sudah berubah atau tindakan tidak dapat diulang.', 422 => 'Periksa kembali data yang diisi.', 429 => 'Terlalu banyak permintaan. Coba lagi nanti.', default => 'Permintaan tidak dapat diproses.'
            };
            if ($exception instanceof IdempotencyConflictException) {
                $code = 'IDEMPOTENCY_CONFLICT';
                $message = $exception->getMessage();
            }
            if ($exception instanceof FinancialException) {
                $code = $exception->errorCode;
                $message = $exception->getMessage();
            }
            $details = $exception instanceof ValidationException ? $exception->errors() : (object) [];
            $headers = $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [];

            return response()->json(['error' => ['code' => $code, 'message' => $message, 'details' => $details], 'request_id' => (string) Str::uuid()], $status, $headers)->header('Cache-Control', 'no-store');
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
