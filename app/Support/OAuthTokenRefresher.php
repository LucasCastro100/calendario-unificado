<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\OauthProvider;
use App\Exceptions\CalendarProviderException;
use App\Models\CalendarConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Renova access tokens OAuth dos provedores.
 *
 * Pontos que não são óbvios e por isso estão comentados:
 *
 *  - Google pode devolver um `id_token` no refresh; ele NÃO substitui o
 *    `refresh_token`. Guardá-lo sobrescreveria o refresh token com um token
 *    de identidade, quebrando a renovação seguinte.
 *  - O mesmo vale para o Microsoft: só persiste `refresh_token` quando a
 *    resposta realmente trouxer um novo.
 *  - O lock evita corrida: várias requisições simultâneas do mesmo usuário
 *    veriam `needsRefresh()` verdadeiro ao mesmo tempo e disparariam N refreshes,
 *    o que é rejeitado pelos provedores e pode revogar a conexão.
 */
final class OAuthTokenRefresher
{
    /**
     * Tempo máximo de lock. Um refresh legítimo leva menos de 2s; 10s é folga
     * para rede lenta sem deixar a fila travada.
     */
    private const LOCK_SECONDS = 10;

    public function __construct(private readonly int $lockSeconds = self::LOCK_SECONDS) {}

    /**
     * Garante que a conexão tenha um access token utilizável.
     *
     * @throws CalendarProviderException
     */
    public function ensureFreshToken(CalendarConnection $connection): CalendarConnection
    {
        if (! $connection->isUsable()) {
            throw CalendarProviderException::reauthRequired($connection->provider, 'Conexão ausente ou revogada');
        }

        if (! $connection->needsRefresh()) {
            return $connection;
        }

        if (! $connection->canRefresh()) {
            // Sem refresh token não há como renovar: o usuário precisa reconectar.
            $connection->forceFill(['scopes_revoked_at' => now()])->save();

            throw CalendarProviderException::reauthRequired(
                $connection->provider,
                'Sem refresh token disponível',
            );
        }

        $lock = Cache::lock("oauth:refresh:{$connection->id}", $this->lockSeconds);

        if (! $lock->get()) {
            // Outra requisição já está renovando. Recarrega do banco: por
            // definição ela termina antes de nós precisarmos do token.
            return $connection->fresh() ?? $connection;
        }

        try {
            return $this->refresh($connection);
        } finally {
            $lock->release();
        }
    }

    /**
     * @throws CalendarProviderException
     */
    private function refresh(CalendarConnection $connection): CalendarConnection
    {
        $provider = $connection->provider;
        $config = $this->clientConfig($provider);

        try {
            $response = Http::asForm()
                ->timeout(15)
                ->post($config['token_url'], [
                    'client_id' => $config['client_id'],
                    'client_secret' => $config['client_secret'],
                    'refresh_token' => (string) $connection->refresh_token,
                    'grant_type' => 'refresh_token',
                ]);
        } catch (Throwable $exception) {
            Log::warning('OAuth refresh request failed', [
                'provider' => $provider->value,
                'connection_id' => $connection->id,
                'error' => $exception->getMessage(),
            ]);

            throw CalendarProviderException::requestFailed($provider, $exception->getMessage());
        }

        if (! $response->successful()) {
            Log::warning('OAuth refresh rejected', [
                'provider' => $provider->value,
                'connection_id' => $connection->id,
                'status' => $response->status(),
            ]);

            // 400/401 no refresh significa refresh token revogado ou expirado:
            // o usuário precisa refazer o consentimento.
            if (in_array($response->status(), [400, 401, 403], true)) {
                $connection->forceFill(['scopes_revoked_at' => now()])->save();

                throw CalendarProviderException::reauthRequired($provider, 'Refresh token rejeitado');
            }

            throw CalendarProviderException::requestFailed($provider, 'HTTP '.$response->status());
        }

        $accessToken = $response->json('access_token');

        if (! is_string($accessToken) || $accessToken === '') {
            throw CalendarProviderException::requestFailed($provider, 'Resposta sem access_token');
        }

        $expiresIn = (int) ($response->json('expires_in') ?? 3600);

        $attributes = [
            'access_token' => $accessToken,
            'token_expires_at' => now()->addSeconds($expiresIn),
        ];

        // Só sobrescreve o refresh token se o provedor devolveu um novo.
        $newRefreshToken = $response->json('refresh_token');

        if (is_string($newRefreshToken) && $newRefreshToken !== '') {
            $attributes['refresh_token'] = $newRefreshToken;
        }

        $scopes = $response->json('scope');

        if (is_string($scopes) && $scopes !== '') {
            $attributes['scopes'] = array_values(array_filter(explode(' ', $scopes)));
        }

        $connection->forceFill($attributes)->save();

        return $connection->refresh();
    }

    /**
     * @return array{token_url: string, client_id: string, client_secret: string}
     */
    private function clientConfig(OauthProvider $provider): array
    {
        return match ($provider) {
            OauthProvider::Google => [
                'token_url' => 'https://oauth2.googleapis.com/token',
                'client_id' => (string) config('services.google.client_id'),
                'client_secret' => (string) config('services.google.client_secret'),
            ],
            OauthProvider::Microsoft => [
                'token_url' => 'https://login.microsoftonline.com/'.config('services.microsoft.tenant', 'common').'/oauth2/v2.0/token',
                'client_id' => (string) config('services.microsoft.client_id'),
                'client_secret' => (string) config('services.microsoft.client_secret'),
            ],
        };
    }
}
