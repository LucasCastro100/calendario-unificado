<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\OauthProvider;
use App\Exceptions\CalendarProviderException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

/**
 * Interpreta respostas de falha do Google Calendar API e do Microsoft Graph,
 * traduzindo para exceção de domínio com mensagem genérica.
 *
 * Concentrar o mapeamento aqui evita que o mesmo `match ($response->status())`
 * seja reescrito em cada adapter — e garante que nenhuma mensagem do terceiro
 * vaze para o cliente.
 */
final class ProviderFailureHandler
{
    public static function handle(Response $response, OauthProvider $provider): never
    {
        $detail = self::detail($response);

        $exception = match (true) {
            $response->status() === 401, $response->status() === 403 => CalendarProviderException::unauthorized($provider, $detail),
            $response->status() === 429 => CalendarProviderException::rateLimited($provider, $detail),
            $response->status() >= 500 => CalendarProviderException::serverError($provider, $detail),
            default => CalendarProviderException::requestFailed($provider, $detail),
        };

        Log::warning('Calendar provider request failed', $exception->context() + [
            'status' => $response->status(),
            'body' => $detail,
        ]);

        throw $exception;
    }

    /**
     * Detalhe técnico para o log. Pode conter mensagem do terceiro, então nunca
     * deve ser devolvido ao cliente.
     */
    private static function detail(Response $response): string
    {
        $body = $response->body();

        if ($body === '') {
            return 'HTTP '.$response->status();
        }

        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            return mb_substr($body, 0, 300);
        }

        $message = $decoded['error']['message']
            ?? $decoded['error']['error_description']
            ?? $decoded['error_description']
            ?? $decoded['error']
            ?? null;

        return is_string($message) ? mb_substr($message, 0, 300) : mb_substr($body, 0, 300);
    }

    /**
     * Segundos de espera sugeridos pelo provider, para o header Retry-After.
     */
    public static function retryAfterSeconds(Response $response, int $default = 60): int
    {
        $header = $response->header('Retry-After');

        if ($header !== null && is_numeric($header)) {
            return (int) $header;
        }

        return $default;
    }
}
