<?php

declare(strict_types=1);

use App\Exceptions\CalendarProviderException;
use App\Http\Middleware\UniversalRefreshTokenMiddleware;
use App\Providers\CalendarServiceProvider;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum em modo SPA: os cookies de sessão precisam de domínio
        // compartilhado entre o Next (:3000) e a API (:8000). Configurado em
        // config/sanctum.php a partir de SANCTUM_STATEFUL_DOMAINS.
        $middleware->statefulApi();

        $middleware->alias([
            'universal.refresh-token' => UniversalRefreshTokenMiddleware::class,
        ]);
    })
    ->withProviders([
        CalendarServiceProvider::class,
    ])
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /*
        |----------------------------------------------------------------------
        | Erros de provider
        |----------------------------------------------------------------------
        |
        | Traduzidos para status HTTP estável. O detalhe técnico já foi logado
        | pelo ProviderFailureHandler; o cliente recebe mensagem genérica —
        | nunca a mensagem do Google/Graph, que pode conter URL com token.
        |
        */
        $exceptions->render(function (CalendarProviderException $exception): JsonResponse {
            // O `statusCode` da exceção já é o status final da resposta. O
            // handler só traduz para um código estável de máquina, para o
            // frontend decidir entre "tentar de novo" e "oferecer reconectar".
            $code = match ($exception->statusCode) {
                422 => 'PROVIDER_REAUTH_REQUIRED',
                429 => 'PROVIDER_RATE_LIMITED',
                default => 'PROVIDER_ERROR',
            };

            return response()->json([
                'message' => $exception->getMessage(),
                'code' => $code,
                'provider' => $exception->provider->value,
            ], $exception->statusCode, $exception->statusCode === 429 ? ['Retry-After' => '60'] : []);
        });

        $exceptions->render(function (ValidationException $exception): JsonResponse {
            return response()->json([
                'message' => $exception->validator->errors()->first() ?: 'Dados inválidos.',
                'errors' => $exception->errors(),
            ], $exception->status);
        });

        $exceptions->render(function (AuthorizationException $exception): JsonResponse {
            return response()->json([
                'message' => 'Acesso negado.',
                'code' => 'FORBIDDEN',
            ], 403);
        });
    })->create();
