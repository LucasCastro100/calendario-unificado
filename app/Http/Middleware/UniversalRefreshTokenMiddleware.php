<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\OauthProvider;
use App\Exceptions\CalendarProviderException;
use App\Models\CalendarConnection;
use App\Services\Contracts\CalendarServiceInterface;
use App\Support\OAuthTokenRefresher;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Garante access token válido ANTES da requisição chegar ao controller.
 *
 * É "universal" porque serve às duas formas de rota:
 *
 *  - com `{provider}` no path (`/api/calendar/google/events`): renova só aquela
 *    conexão e injeta no request para o controller reaproveitar;
 *  - sem provider (`/api/calendar/events`): renova todas as conexões do usuário.
 *
 * Na rota agregada uma conexão que exige reconexão **não** aborta a requisição:
 * ela é marcada em `refresh_stale_provider` e o orquestrador a exclui, porque o
 * contrato da listagem unificada é degradação parcial, não erro fatal. Só a rota
 * de um provider específico propaga o erro (422), porque ali o usuário pediu
 * aquela conta explicitamente.
 */
final class UniversalRefreshTokenMiddleware
{
    /**
     * Atributo do request com o provider que precisa de reconexão.
     */
    public const STALE_ATTRIBUTE = 'refresh_stale_provider';

    public function __construct(
        private readonly OAuthTokenRefresher $refresher,
    ) {}

    public function handle(Request $request, Closure $next, ?string $provider = null): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $provider ??= $this->providerFromRoute($request);

        if ($provider === null) {
            $this->refreshAll($request, $user->getAuthIdentifier());
        } else {
            $this->refreshOne($request, $user->getAuthIdentifier(), $provider);
        }

        return $next($request);
    }

    /**
     * Rota agregada: renova todas as conexões e segue adiante, isolando quem
     * precisar de reconexão.
     */
    private function refreshAll(Request $request, int|string $userId): void
    {
        CalendarConnection::query()
            ->where('user_id', $userId)
            ->get()
            ->each(function (CalendarConnection $connection) use ($request): void {
                try {
                    $this->refresher->ensureFreshToken($connection);
                } catch (CalendarProviderException $exception) {
                    // Falha parcial: segue com os providers que responderam.
                    Log::warning('Provider marked for reauth before aggregation', $exception->context() + [
                        'user_id' => $connection->user_id,
                    ]);

                    $stale = $request->attributes->get(self::STALE_ATTRIBUTE, []);
                    $request->attributes->set(self::STALE_ATTRIBUTE, [
                        ...$stale,
                        $connection->provider->value,
                    ]);
                }
            });
    }

    /**
     * Rota de um provider: propaga o erro, porque não há resposta parcial a dar.
     */
    private function refreshOne(Request $request, int|string $userId, string $provider): void
    {
        $connection = CalendarConnection::query()
            ->where('user_id', $userId)
            ->where('provider', $provider)
            ->first();

        if ($connection === null) {
            return;
        }

        // 401 do provider vira 422 com código estável, para o frontend oferecer
        // "reconectar" em vez de mostrar erro genérico.
        $this->refresher->ensureFreshToken($connection);

        // Injeta a conexão resolvida para não repetir a query no service.
        $request->attributes->set('calendar_connection', $connection);
    }

    /**
     * Segmento `{provider}` da rota, se existir e for um provider conhecido.
     */
    private function providerFromRoute(Request $request): ?string
    {
        $value = $request->route('provider');

        if (! is_string($value)) {
            return null;
        }

        return OauthProvider::tryFrom($value)?->value;
    }

    /**
     * Providers marcados para reconexão na rota agregada.
     *
     * @return array<int, string>
     */
    public static function staleProviders(Request $request): array
    {
        $value = $request->attributes->get(self::STALE_ATTRIBUTE, []);

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    /**
     * Helper para o controller: conexão garantida pelo middleware.
     */
    public static function connection(Request $request): ?CalendarConnection
    {
        $connection = $request->attributes->get('calendar_connection');

        return $connection instanceof CalendarConnection ? $connection : null;
    }

    /**
     * Resolve o adapter do provider da rota — o container decide a implementação.
     */
    public static function service(Request $request): ?CalendarServiceInterface
    {
        $connection = self::connection($request);

        if ($connection === null) {
            return null;
        }

        return app(CalendarServiceInterface::class, ['provider' => $connection->provider]);
    }
}
