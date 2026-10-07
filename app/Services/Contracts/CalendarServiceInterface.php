<?php

declare(strict_types=1);

namespace App\Services\Contracts;

use App\DTOs\Calendar\CalendarRange;
use App\DTOs\Calendar\EventPageDTO;
use App\DTOs\Calendar\UnifiedEventDTO;
use App\Enums\OauthProvider;
use App\Models\CalendarConnection;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;

/**
 * Contrato de um adaptador de provedor de calendário (Adapter/Strategy).
 *
 * A leitura é dividida em duas etapas de propósito:
 *
 *  1. `listRequest()` devolve a spec da requisição — só dados, sem I/O. É isso
 *     que o `UnifiedCalendarService` entrega ao `Http::pool()`, que dispara as
 *     chamadas de todos os providers em paralelo.
 *  2. `parsePage()` normaliza a resposta. Só roda **depois** que a rede
 *     respondeu, então fica fora do pool.
 *
 * Sem essa separação o pool não teria efeito: cada adapter estaria fazendo o
 * próprio `Http::get()` sequencialmente e a promessa de paralelismo seria falsa.
 *
 * As operações de escrita permanecem síncronas: usuário age em um provider por vez.
 */
interface CalendarServiceInterface
{
    /**
     * Eventos de um provider no intervalo, já normalizados em DTO.
     *
     * Entrada de alto nível para quando se consulta um provider só (tela de
     * detalhe, sincronização pontual, testes). A agregação multi-provider NÃO
     * passa por aqui: ela usa `listRequest()`/`prepareRequest()`/`parsePage()`
     * para conseguir disparar as chamadas em paralelo via `Http::pool()`.
     *
     * @return array<int, UnifiedEventDTO>
     */
    public function getEvents(OauthProvider $provider, string $startDate, string $endDate): array;

    /**
     * Spec da primeira requisição de listagem. Sem efeito colateral — nada de rede.
     *
     * @return array{url: string, query: array<string, mixed>, headers: array<string, string>}
     */
    public function listRequest(CalendarConnection $connection, CalendarRange $range): array;

    /**
     * Aplica token, headers base e timeout à requisição em construção.
     *
     * Recebe a `PendingRequest` em vez de criar uma nova porque, na leitura
     * agregada, a instância vem do `Http::pool()` (`$pool->as($key)`). Criar uma
     * request nova ali a desconectaria do pool e a_parallelização viraria uma
     * sequência disfarçada — o `Pool` só envia o que foi registrado nele.
     *
     * @param  array{url: string, query: array<string, mixed>, headers: array<string, string>}  $spec
     */
    public function prepareRequest(PendingRequest $request, CalendarConnection $connection, array $spec): PendingRequest;

    /**
     * Normaliza o payload bruto do provider em uma página de DTO.
     *
     * Recebe a spec usada na requisição porque a página seguinte é derivada dela
     * (o Google reaproveita a URL base + `pageToken`; o Graph devolve um
     * `@odata.nextLink` absoluto).
     *
     * @param  array<string, mixed>  $payload
     * @param  array{url: string, query: array<string, mixed>, headers: array<string, string>}  $spec
     */
    public function parsePage(array $payload, CalendarConnection $connection, array $spec): EventPageDTO;

    /**
     * Leitura completa de um provider, paginada. Caminho síncrono, usado quando
     * se chama um provider isolado (sem ganho em paralelizar).
     *
     * @return Collection<int, UnifiedEventDTO>
     */
    public function events(User $user, CalendarConnection $connection, CalendarRange $range): Collection;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createEvent(User $user, CalendarConnection $connection, array $attributes): UnifiedEventDTO;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateEvent(User $user, CalendarConnection $connection, string $eventId, array $attributes): UnifiedEventDTO;

    /**
     * Remove um evento remoto. `$eventId` é sempre id do provedor, nunca id local.
     */
    public function deleteEvent(User $user, CalendarConnection $connection, string $eventId): void;

    /**
     * Identificadores dos calendários do usuário no provider.
     *
     * @return Collection<int, array{id: string, name: string, primary: bool}>
     */
    public function calendars(User $user, CalendarConnection $connection): Collection;
}
