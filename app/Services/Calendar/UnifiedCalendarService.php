<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use App\DTOs\Calendar\CalendarRange;
use App\DTOs\Calendar\EventPageDTO;
use App\DTOs\Calendar\UnifiedEventDTO;
use App\Enums\EventStatus;
use App\Enums\EventVisibility;
use App\Enums\OauthProvider;
use App\Exceptions\CalendarProviderException;
use App\Models\CalendarConnection;
use App\Models\CalendarEventCache;
use App\Models\User;
use App\Services\Contracts\CalendarServiceInterface;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Orquestrador multi-provider.
 *
 * A **primeira página** de cada provider é disparada em paralelo via
 * `Http::pool()`: os providers são independentes e a latência é dominada pela
 * rede, então o total passa a ser o do provider mais lento, não a soma. As
 * páginas seguintes (raras na prática) são sequenciais, porque só existem
 * depois que a primeira responde.
 *
 * Um provider em falha NÃO derruba a resposta: o erro vai para
 * `failedProviders()` e o cache local cobre o buraco. O frontend usa essa lista
 * para avisar que a tela está parcial.
 */
final class UnifiedCalendarService
{
    /**
     * Teto de páginas sought após a primeira, buscado sequencialmente.
     */
    private const MAX_EXTRA_PAGES = 5;

    /**
     * @var array<int, string>
     */
    private array $failedProviders = [];

    /**
     * @return array<int, string>
     */
    public function failedProviders(): array
    {
        return $this->failedProviders;
    }

    /**
     * Eventos unificados de um intervalo, de todos os providers conectados.
     *
     * A renovação de token é responsabilidade do `UniversalRefreshTokenMiddleware`,
     * que roda antes do controller. Quem precisa de reconexão chega aqui em
     * `$staleProviders` e é excluído do pool: a listagem unificada degrada de
     * forma parcial, então um provider morto não pode derrubar os outros.
     *
     * @param  array<int, OauthProvider>|null  $providers  Filtro opcional.
     * @param  array<int, string>  $staleProviders  Marcados pelo middleware.
     * @return Collection<int, UnifiedEventDTO>
     */
    public function events(
        User $user,
        CalendarRange $range,
        ?array $providers = null,
        array $staleProviders = [],
    ): Collection {
        $this->failedProviders = $staleProviders;

        $connections = $this->usableConnections($user, $providers)
            ->reject(
                static fn (CalendarConnection $connection): bool => in_array(
                    $connection->provider->value,
                    $staleProviders,
                    true,
                ),
            )
            ->values();

        if ($connections->isEmpty()) {
            return collect();
        }

        $services = $connections->mapWithKeys(
            fn (CalendarConnection $c): array => [$c->provider->value => $this->resolveService($c->provider)],
        );

        $events = $this->fetchFirstPagesInParallel($user, $connections, $services, $range);

        $this->persistCache($user, $events);

        return $events
            ->unique('uid')
            ->sortBy('start')
            ->values();
    }

    /**
     * Fan-out da primeira página de cada provider dentro de um Http::pool.
     *
     * @param  Collection<int, CalendarConnection>  $connections
     * @param  Collection<string, CalendarServiceInterface>  $services
     * @return Collection<int, UnifiedEventDTO>
     */
    private function fetchFirstPagesInParallel(
        User $user,
        Collection $connections,
        Collection $services,
        CalendarRange $range,
    ): Collection {
        $specs = $connections->mapWithKeys(
            fn (CalendarConnection $c): array => [
                $c->provider->value => $services[$c->provider->value]->listRequest($c, $range),
            ],
        );

        /*
         * `Http::pool()` só envia as requisições registradas no `Pool` — via
         * `$pool->as($chave)`. O callback precisa devolver nada: o Laravel
         * coleta `$pool->getRequests()` e resolve as promises. Devolver
         * `PendingRequest`/`Response` do callback (sem registrar) faria o pool
         * enviar zero requisições e a agregação responderia vazia.
         */
        $responses = Http::pool(function (Pool $pool) use ($connections, $services, $specs): void {
            $connections->each(function (CalendarConnection $connection) use ($pool, $services, $specs): void {
                $key = $connection->provider->value;
                $spec = $specs[$key];

                $request = $services[$key]
                    ->prepareRequest($pool->as($key), $connection, $spec)
                    // `throw: false` porque a falha de um provider é registrada
                    // em `failedProviders`, não convertida em exceção — o
                    // Throwable abortaria o batch inteiro e perderia os demais.
                    ->retry(2, 250, throw: false);

                $spec['query'] === []
                    ? $request->get($spec['url'])
                    : $request->get($spec['url'], $spec['query']);
            });
        });

        $events = collect();

        foreach ($connections as $connection) {
            $key = $connection->provider->value;
            $response = $responses[$key] ?? null;

            if ($response === null || ! $response->successful()) {
                $this->handleProviderFailure($user, $connection, $response, $range, $events);

                continue;
            }

            $page = $services[$key]->parsePage($response->json() ?? [], $connection, $specs[$key]);
            $events = $events->merge($page->events);

            // Páginas extras são sequenciais: só existem porque a primeira já
            // voltou, então não há mais o que paralelizar.
            $events = $events->merge(
                $this->fetchRemainingPages($user, $connection, $services[$key], $page, $range),
            );
        }

        return $events;
    }

    /**
     * Páginas adicionais são buscadas sequencialmente: só existem porque a
     * primeira já voltou, então não há mais o que paralelizar.
     *
     * @return Collection<int, UnifiedEventDTO>
     */
    private function fetchRemainingPages(
        User $user,
        CalendarConnection $connection,
        CalendarServiceInterface $service,
        EventPageDTO $page,
        CalendarRange $range,
    ): Collection {
        $events = collect();
        $pages = 0;

        while ($page->hasNextPage() && $pages < self::MAX_EXTRA_PAGES) {
            $spec = $page->nextRequest;

            try {
                $response = $service
                    ->prepareRequest(Http::acceptJson(), $connection, $spec)
                    ->retry(2, 250, throw: false)
                    ->get($spec['url'], $spec['query']);

                if (! $response->successful()) {
                    throw $this->toProviderException($connection->provider, $response);
                }
            } catch (CalendarProviderException $exception) {
                $this->failedProviders[] = $connection->provider->value;

                Log::warning('Provider failed while paginating', $exception->context() + [
                    'user_id' => $user->id,
                    'page' => $pages + 1,
                ]);

                break;
            }

            $page = $service->parsePage($response->json() ?? [], $connection, $spec);
            $events = $events->merge($page->events);
            $pages++;
        }

        return $events;
    }

    /**
     * Falha parcial: registra o provider e completa com o cache local.
     *
     * @param  Collection<int, UnifiedEventDTO>  $events
     */
    private function handleProviderFailure(
        User $user,
        CalendarConnection $connection,
        ?Response $response,
        CalendarRange $range,
        Collection &$events,
    ): void {
        $provider = $connection->provider;
        $this->failedProviders[] = $provider->value;

        if ($response !== null) {
            Log::warning('Provider failed during aggregation', [
                'provider' => $provider->value,
                'user_id' => $user->id,
                'status' => $response->status(),
            ]);
        }

        $events = $events->merge($this->cachedEvents($user, $connection, $range));
    }

    private function toProviderException(OauthProvider $provider, Response $response): CalendarProviderException
    {
        return match (true) {
            $response->status() === 401, $response->status() === 403 => CalendarProviderException::unauthorized($provider),
            $response->status() === 429 => CalendarProviderException::rateLimited($provider),
            $response->status() >= 500 => CalendarProviderException::serverError($provider),
            default => CalendarProviderException::requestFailed($provider),
        };
    }

    /**
     * Eventos de um único provider. O erro é propagado — quem chamou pediu
     * aquele provider explicitamente, então falhar em silêncio seria enganoso.
     *
     * @return Collection<int, UnifiedEventDTO>
     *
     * @throws CalendarProviderException
     */
    public function eventsFromProvider(User $user, OauthProvider $provider, CalendarRange $range): Collection
    {
        $connection = $this->usableConnections($user, [$provider])->first();

        if ($connection === null) {
            return collect();
        }

        return $this->resolveService($provider)->events($user, $connection, $range);
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws CalendarProviderException
     */
    public function createEvent(User $user, OauthProvider $provider, array $attributes): UnifiedEventDTO
    {
        $connection = $this->requireConnection($user, $provider, write: true);
        $event = $this->resolveService($provider)->createEvent($user, $connection, $attributes);

        $this->invalidateCache($user, $provider, $event->remoteId);

        return $event;
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws CalendarProviderException
     */
    public function updateEvent(User $user, OauthProvider $provider, string $eventId, array $attributes): UnifiedEventDTO
    {
        $connection = $this->requireConnection($user, $provider, write: true);
        $event = $this->resolveService($provider)->updateEvent($user, $connection, $eventId, $attributes);

        $this->invalidateCache($user, $provider, $eventId);

        return $event;
    }

    /**
     * @throws CalendarProviderException
     */
    public function deleteEvent(User $user, OauthProvider $provider, string $eventId): void
    {
        $connection = $this->requireConnection($user, $provider, write: true);

        $this->resolveService($provider)->deleteEvent($user, $connection, $eventId);

        $this->invalidateCache($user, $provider, $eventId);
    }

    /**
     * @return Collection<int, array{id: string, name: string, primary: bool}>
     *
     * @throws CalendarProviderException
     */
    public function calendars(User $user, OauthProvider $provider): Collection
    {
        $connection = $this->requireConnection($user, $provider, write: false);

        return $this->resolveService($provider)->calendars($user, $connection);
    }

    /**
     * Resolve o adapter a partir do enum — quem decide a implementação é o
     * container (CalendarServiceProvider), nunca um `if` de provider no código.
     */
    private function resolveService(OauthProvider $provider): CalendarServiceInterface
    {
        return app(CalendarServiceInterface::class, ['provider' => $provider]);
    }

    /**
     * @param  array<int, OauthProvider>|null  $providers
     * @return Collection<int, CalendarConnection>
     */
    private function usableConnections(User $user, ?array $providers = null): Collection
    {
        $query = $user->calendarConnections()->usable();

        if ($providers !== null && $providers !== []) {
            $query->whereIn('provider', array_map(
                static fn (OauthProvider $p): string => $p->value,
                $providers,
            ));
        }

        return $query->get();
    }

    /**
     * @throws ValidationException
     */
    private function requireConnection(User $user, OauthProvider $provider, bool $write): CalendarConnection
    {
        $connection = $this->usableConnections($user, [$provider])->first();

        if ($connection === null) {
            throw ValidationException::withMessages([
                'provider' => ['Você não tem uma conta '.$provider->label().' conectada.'],
            ]);
        }

        if ($write && ! $connection->canWrite()) {
            throw ValidationException::withMessages([
                'provider' => ['Sua conexão com '.$provider->label().' é somente leitura. Reconecte e autorize a edição.'],
            ]);
        }

        return $connection;
    }

    /**
     * Eventos em cache do provider que falhou, para a tela não ficar com buraco.
     *
     * @return Collection<int, UnifiedEventDTO>
     */
    private function cachedEvents(User $user, CalendarConnection $connection, CalendarRange $range): Collection
    {
        return CalendarEventCache::query()
            ->where('user_id', $user->id)
            ->where('connection_id', $connection->id)
            ->overlapping($range->start, $range->end)
            ->get()
            ->map(static function (CalendarEventCache $cache): ?UnifiedEventDTO {
                // O cast do model já devolve o enum; tryFrom é só a guarda
                // contra registro com valor desconhecido (ex.: provider removido
                // do enum numa atualização futura).
                $provider = $cache->provider;

                if ($provider === null || $cache->starts_at === null || $cache->ends_at === null) {
                    return null;
                }

                $payload = $cache->payload;

                try {
                    return new UnifiedEventDTO(
                        uid: (string) $cache->uid,
                        provider: $provider,
                        remoteId: (string) $cache->remote_id,
                        calendarId: $payload['calendarId'] ?? null,
                        title: (string) ($payload['title'] ?? '(sem título)'),
                        description: $payload['description'] ?? null,
                        location: $payload['location'] ?? null,
                        start: $cache->starts_at->toImmutable(),
                        end: $cache->ends_at->toImmutable(),
                        allDay: (bool) ($payload['allDay'] ?? false),
                        status: EventStatus::tryFrom((string) ($payload['status'] ?? 'confirmed')) ?? EventStatus::Confirmed,
                        visibility: EventVisibility::tryFrom((string) ($payload['visibility'] ?? 'default')) ?? EventVisibility::Default,
                        webLink: $payload['webLink'] ?? null,
                        responseStatus: $payload['responseStatus'] ?? null,
                    );
                } catch (\Throwable) {
                    // Payload em cache corrompido (ou de versão antiga do DTO)
                    // não pode derrubar a leitura inteira.
                    return null;
                }
            })
            ->filter()
            ->values();
    }

    /**
     * Persiste o cache local em lote. `upsort` em vez de `create` para que a
     * segunda carga do mesmo intervalo atualize em vez de violar o unique.
     *
     * @param  Collection<int, UnifiedEventDTO>  $events
     */
    private function persistCache(User $user, Collection $events): void
    {
        if ($events->isEmpty()) {
            return;
        }

        $connectionIds = $user->calendarConnections()->pluck('id', 'provider');

        $rows = $events->map(function (UnifiedEventDTO $event) use ($user, $connectionIds): ?array {
            $connectionId = $connectionIds[$event->provider->value] ?? null;

            if ($connectionId === null) {
                return null;
            }

            return [
                'user_id' => $user->id,
                'connection_id' => $connectionId,
                'provider' => $event->provider->value,
                'remote_id' => $event->remoteId,
                'uid' => $event->uid,
                'payload' => json_encode($event->toArray(), JSON_THROW_ON_ERROR),
                'starts_at' => $event->start,
                'ends_at' => $event->end,
                'fetched_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        })->filter()->values();

        if ($rows->isEmpty()) {
            return;
        }

        try {
            DB::transaction(static function () use ($rows): void {
                CalendarEventCache::upsert(
                    $rows->all(),
                    ['user_id', 'uid'],
                    ['payload', 'starts_at', 'ends_at', 'fetched_at', 'updated_at'],
                );
            });
        } catch (\Throwable $exception) {
            // Cache é otimização: falhar aqui não pode derrubar a leitura.
            Log::warning('Failed to persist calendar event cache', [
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Limpa o cache dos eventos de um provider após uma escrita.
     */
    public function invalidateCache(User $user, OauthProvider $provider, ?string $remoteId = null): void
    {
        $query = CalendarEventCache::where('user_id', $user->id)
            ->where('provider', $provider->value);

        if ($remoteId !== null) {
            $query->where('remote_id', $remoteId);
        }

        $query->delete();
    }
}
