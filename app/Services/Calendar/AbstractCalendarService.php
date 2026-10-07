<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use App\DTOs\Calendar\CalendarRange;
use App\DTOs\Calendar\UnifiedEventDTO;
use App\Enums\OauthProvider;
use App\Models\CalendarConnection;
use App\Models\User;
use App\Services\Contracts\CalendarServiceInterface;
use App\Support\ProviderFailureHandler;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

/**
 * Base dos adapters de calendário.
 *
 * Existe para não duplicar em cada provider a mesma lógica de paginação e de
 * resolução de conexão: o que muda entre Google e Microsoft é apenas o dialeto
 * (URL, payload, normalização), e é isso que as implementações concretas
 * descrevem.
 *
 * A ordem dos blocos de I/O é sempre a mesma:
 *
 *  1. `listRequest()` monta a spec — sem rede.
 *  2. `prepareRequest()` aplica token e headers numa requisição em construção.
 *  3. `parsePage()` normaliza a resposta.
 *
 * A agregação do `UnifiedCalendarService` usa 1 e 2 para o `Http::pool()`; o
 * caminho de um provider só usa os três em sequência.
 */
abstract class AbstractCalendarService implements CalendarServiceInterface
{
    /**
     * Teto de páginas por provider. Existe para uma paginação patológica
     * (loop no `nextLink`) não virar loop infinito consumindo cota da API.
     */
    protected const MAX_PAGES = 5;

    /**
     * Provider atendido por este adapter.
     */
    abstract public function provider(): OauthProvider;

    /**
     * {@inheritDoc}
     *
     * @return array<int, UnifiedEventDTO>
     */
    public function getEvents(OauthProvider $provider, string $startDate, string $endDate): array
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new \LogicException(
                'getEvents() exige um usuário autenticado; na rota HTTP use o controller, que já o tem.'
            );
        }

        $connection = $user->calendarConnections()
            ->where('provider', $provider->value)
            ->first();

        // Sem conexão não é erro: o usuário simplesmente não conectou esta conta.
        if ($connection === null) {
            return [];
        }

        return $this->events($user, $connection, CalendarRange::fromIso($startDate, $endDate))
            ->all();
    }

    /**
     * {@inheritDoc}
     */
    public function events(User $user, CalendarConnection $connection, CalendarRange $range): Collection
    {
        $spec = $this->listRequest($connection, $range);
        $events = collect();
        $pages = 0;

        while ($pages < self::MAX_PAGES) {
            $response = $this->prepareRequest(Http::acceptJson(), $connection, $spec)
                ->get($spec['url'], $spec['query']);

            if (! $response->successful()) {
                ProviderFailureHandler::handle($response, $this->provider());
            }

            $page = $this->parsePage($response->json() ?? [], $connection, $spec);
            $events = $events->merge($page->events);

            if (! $page->hasNextPage()) {
                break;
            }

            $spec = $page->nextRequest;
            $pages++;
        }

        return $events;
    }
}
