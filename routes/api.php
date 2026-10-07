<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CalendarController;
use App\Http\Controllers\Api\ConnectionController;
use App\Http\Controllers\Api\OAuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Prefixo `/api` aplicado automaticamente. Todas as respostas são JSON —
| `bootstrap/app.php` garante o render JSON para rotas `api/*`.
|
| O browser nunca chama estas rotas diretamente: o Next.js as consome
| server-side e repassa o cookie de sessão. Ver security-rules.md seção 6.
|
*/

// ── Autenticação ──────────────────────────────────────────────────────────
Route::prefix('auth')->group(function (): void {
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:auth');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:auth');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

/*
|--------------------------------------------------------------------------
| Rotas autenticadas
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function (): void {
    // ── Conexões ───────────────────────────────────────────────────────────
    Route::get('connections', [ConnectionController::class, 'index'])->middleware('throttle:calendar-read');

    // ── OAuth ──────────────────────────────────────────────────────────────
    // Mais restritivo que o resto: o consentimento e a troca de code são
    // alvos fáceis de abuso e o provider pode banir o app.
    Route::prefix('oauth')->middleware('throttle:oauth')->group(function (): void {
        Route::get('{provider}/redirect', [OAuthController::class, 'redirect']);
        Route::get('{provider}/callback', [OAuthController::class, 'callback']);
        Route::delete('{provider}', [OAuthController::class, 'disconnect']);
    });

    // ── Calendário (agregado, sem provider no path) ───────────────────────
    // `universal.refresh-token` renova todas as conexões do usuário e marca as
    // que precisam de reconexão — a listagem degrada de forma parcial.
    Route::prefix('calendar')->middleware('throttle:calendar-read')->group(function (): void {
        Route::get('events', [CalendarController::class, 'index'])
            ->middleware('universal.refresh-token');
    });

    // ── Calendário (um provider por vez) ───────────────────────────────────
    // Mesmo middleware, mas com `{provider}` no path: ele renova só aquela
    // conexão e propaga o erro (422) em vez de degradar.
    Route::prefix('calendar/{provider}')->group(function (): void {
        Route::get('calendars', [CalendarController::class, 'calendars'])
            ->middleware(['universal.refresh-token', 'throttle:calendar-read']);

        Route::get('events', [CalendarController::class, 'show'])
            ->middleware(['universal.refresh-token', 'throttle:calendar-read']);

        Route::post('events', [CalendarController::class, 'store'])
            ->middleware(['universal.refresh-token', 'throttle:calendar-write']);

        Route::patch('events/{eventId}', [CalendarController::class, 'update'])
            ->middleware(['universal.refresh-token', 'throttle:calendar-write']);

        Route::delete('events/{eventId}', [CalendarController::class, 'destroy'])
            ->middleware(['universal.refresh-token', 'throttle:calendar-write']);
    });
});
