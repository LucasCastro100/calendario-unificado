<?php

declare(strict_types=1);

namespace App\Services\Google;

use App\DTOs\Calendar\CalendarRange;
use App\DTOs\Calendar\EventPageDTO;
use App\DTOs\Calendar\UnifiedAttendeeDTO;
use App\DTOs\Calendar\UnifiedEventDTO;
use App\Enums\EventStatus;
use App\Enums\EventVisibility;
use App\Enums\OauthProvider;
use App\Exceptions\CalendarProviderException;
use App\Models\CalendarConnection;
use App\Models\User;
use App\Services\Calendar\AbstractCalendarService;
use App\Support\ProviderFailureHandler;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * Adapter da Google Calendar API v3.
 *
 * Responsabilidade única: falar o dialeto do Google e devolver UnifiedEventDTO.
 * Não há regra de negócio aqui — quem decide o que fazer quando este provider
 * falha é o UnifiedCalendarService.
 */
final class GoogleCalendarService extends AbstractCalendarService
{
    private const BASE_URL = 'https://www.googleapis.com/calendar/v3';

    /**
     * Um dia em segundos — converte o `date` (all-day) do Google, que não traz
     * hora, em um instante.
     */
    private const DAY_IN_SECONDS = 86400;

    /**
     * Teto de páginas. Acima disso a resposta é truncada em vez de consumir toda
     * a cota da API do usuário numa única requisição da aplicação.
     */
    public function provider(): OauthProvider
    {
        return OauthProvider::Google;
    }

    /**
     * {@inheritDoc}
     */
    public function listRequest(CalendarConnection $connection, CalendarRange $range): array
    {
        return [
            'url' => $this->eventsUrl($connection),
            'query' => [
                'timeMin' => $range->start->toIso8601String(),
                'timeMax' => $range->end->toIso8601String(),
                // Expande recorrências em instâncias individuais, que é o que o
                // FullCalendar sabe renderizar.
                'singleEvents' => 'true',
                'orderBy' => 'startTime',
                // Pedimos cancelados para o DTO poder marcar o status; a UI
                // decide se exibe ou risca.
                'showDeleted' => 'true',
                'maxResults' => 250,
            ],
            'headers' => [],
        ];
    }

    /**
     * Spec da página seguinte. O Google pagina reaproveitando a mesma URL base
     * com um `pageToken`.
     *
     * @param  array<string, mixed>  $spec
     * @return array{url: string, query: array<string, mixed>, headers: array<string, string>}
     */
    public function nextRequest(array $spec, string $pageToken): array
    {
        return [
            'url' => $spec['url'],
            'query' => $spec['query'] + ['pageToken' => $pageToken],
            'headers' => $spec['headers'],
        ];
    }

    /**
     * O token vai exclusivamente no header — nunca em query string, para não
     * vazar em log de acesso do proxy.
     */
    public function prepareRequest(PendingRequest $request, CalendarConnection $connection, array $spec): PendingRequest
    {
        return $request
            ->withToken((string) $connection->access_token)
            ->acceptJson()
            ->timeout(15)
            ->withHeaders($spec['headers'] ?? []);
    }

    /**
     * {@inheritDoc}
     */
    public function parsePage(array $payload, CalendarConnection $connection, array $spec): EventPageDTO
    {
        $calendarId = $payload['calendarId']
            ?? $connection->calendar_ids[0]
            ?? 'primary';

        $events = collect($payload['items'] ?? [])->map(
            fn (array $item): ?UnifiedEventDTO => $this->toDto($item, $calendarId),
        )->filter()->values();

        $pageToken = $payload['nextPageToken'] ?? null;

        return new EventPageDTO(
            events: $events,
            nextRequest: is_string($pageToken) && $pageToken !== ''
                ? $this->nextRequest($spec, $pageToken)
                : null,
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws CalendarProviderException
     */
    public function createEvent(User $user, CalendarConnection $connection, array $attributes): UnifiedEventDTO
    {
        $response = $this->client($connection)->post(
            $this->eventsUrl($connection),
            $this->toGooglePayload($attributes),
        );

        if (! $response->successful()) {
            ProviderFailureHandler::handle($response, OauthProvider::Google);
        }

        return $this->toDto($response->json() ?? [], $connection->calendar_ids[0] ?? 'primary')
            ?? throw CalendarProviderException::requestFailed(OauthProvider::Google, 'Resposta sem evento');
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws CalendarProviderException
     */
    public function updateEvent(User $user, CalendarConnection $connection, string $eventId, array $attributes): UnifiedEventDTO
    {
        $response = $this->client($connection)->patch(
            $this->eventsUrl($connection).'/'.urlencode($eventId),
            $this->toGooglePayload($attributes),
        );

        if (! $response->successful()) {
            ProviderFailureHandler::handle($response, OauthProvider::Google);
        }

        return $this->toDto($response->json() ?? [], $connection->calendar_ids[0] ?? 'primary')
            ?? throw CalendarProviderException::requestFailed(OauthProvider::Google, 'Resposta sem evento');
    }

    /**
     * @throws CalendarProviderException
     */
    public function deleteEvent(User $user, CalendarConnection $connection, string $eventId): void
    {
        $response = $this->client($connection)->delete(
            $this->eventsUrl($connection).'/'.urlencode($eventId),
        );

        // 410 = já removido. Idempotente, então não é erro.
        if (! $response->successful() && $response->status() !== 410) {
            ProviderFailureHandler::handle($response, OauthProvider::Google);
        }
    }

    /**
     * @return Collection<int, array{id: string, name: string, primary: bool}>
     *
     * @throws CalendarProviderException
     */
    public function calendars(User $user, CalendarConnection $connection): Collection
    {
        $response = $this->client($connection)->get(self::BASE_URL.'/users/me/calendarList', [
            'maxResults' => 250,
            'minAccessRole' => 'reader',
        ]);

        if (! $response->successful()) {
            ProviderFailureHandler::handle($response, OauthProvider::Google);
        }

        return collect($response->json('items', []) ?? [])->map(static fn (array $item): array => [
            'id' => (string) $item['id'],
            'name' => (string) ($item['summaryOverride'] ?? $item['summary'] ?? $item['id']),
            'primary' => (bool) ($item['primary'] ?? false),
        ])->values();
    }

    private function client(CalendarConnection $connection): PendingRequest
    {
        return Http::withToken((string) $connection->access_token)
            ->acceptJson()
            ->timeout(15)
            ->retry(2, 250, throw: false);
    }

    private function eventsUrl(CalendarConnection $connection): string
    {
        $calendarId = $connection->calendar_ids[0] ?? 'primary';

        return self::BASE_URL.'/calendars/'.urlencode($calendarId).'/events';
    }

    /**
     * Traduz o item bruto do Google no DTO único.
     *
     * @param  array<string, mixed>  $item
     */
    private function toDto(array $item, string $calendarId): ?UnifiedEventDTO
    {
        $remoteId = $item['id'] ?? null;

        if ($remoteId === null) {
            return null;
        }

        $start = $this->parseMoment($item['start'] ?? null);

        if ($start === null) {
            return null;
        }

        $end = $this->parseMoment($item['end'] ?? null) ?? $start->addSeconds(self::DAY_IN_SECONDS);

        $attendees = collect($item['attendees'] ?? [])->map(
            static fn (array $attendee): UnifiedAttendeeDTO => UnifiedAttendeeDTO::fromArray($attendee),
        )->values()->all();

        return new UnifiedEventDTO(
            uid: UnifiedEventDTO::makeUid(OauthProvider::Google, (string) $remoteId),
            provider: OauthProvider::Google,
            remoteId: (string) $remoteId,
            calendarId: $calendarId,
            title: (string) ($item['summary'] ?? '(sem título)'),
            description: UnifiedEventDTO::sanitizeText($item['description'] ?? null),
            location: UnifiedEventDTO::sanitizeText($item['location'] ?? null),
            start: $start,
            end: $end,
            allDay: ($item['start']['date'] ?? null) !== null,
            status: $this->mapStatus((string) ($item['status'] ?? 'confirmed')),
            visibility: EventVisibility::tryFrom((string) ($item['visibility'] ?? 'default')) ?? EventVisibility::Default,
            organizer: isset($item['organizer'])
                ? UnifiedAttendeeDTO::fromArray($item['organizer'] + ['isOrganizer' => true])
                : null,
            attendees: $attendees,
            webLink: UnifiedEventDTO::sanitizeUrl($item['htmlLink'] ?? null),
            // `conferenceData` traz os detalhes da call; `hangoutLink` é o
            // link já utilizável. Os dois existem, mas só o segundo serve
            // para o botão "Participar".
            meetingLink: UnifiedEventDTO::sanitizeUrl($item['hangoutLink'] ?? null),
            responseStatus: $this->extractOwnResponseStatus($item),
        );
    }

    /**
     * O Google traz instância all-day em `date` (YYYY-MM-DD) e timed event em
     * `dateTime` (RFC 3339). Ambos viram CarbonImmutable com timezone.
     *
     * @param  array<string, mixed>|null  $moment
     */
    private function parseMoment(?array $moment): ?CarbonImmutable
    {
        if ($moment === null) {
            return null;
        }

        if (isset($moment['dateTime'])) {
            try {
                return CarbonImmutable::parse((string) $moment['dateTime']);
            } catch (\Throwable) {
                return null;
            }
        }

        if (isset($moment['date'])) {
            // All-day: a data vale o dia inteiro e a API omite o fuso, então
            // interpretamos como dia UTC.
            try {
                return CarbonImmutable::parse((string) $moment['date'].'T00:00:00', 'UTC');
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function mapStatus(string $status): EventStatus
    {
        return match ($status) {
            'cancelled' => EventStatus::Cancelled,
            'tentative' => EventStatus::Tentative,
            default => EventStatus::Confirmed,
        };
    }

    /**
     * O Google marca com `self: true` o participante que corresponde ao usuário
     * logado; é esse o status de resposta que interessa.
     *
     * @param  array<string, mixed>  $item
     */
    private function extractOwnResponseStatus(array $item): ?string
    {
        foreach ($item['attendees'] ?? [] as $attendee) {
            if (($attendee['self'] ?? false) === true) {
                return isset($attendee['responseStatus'])
                    ? strtolower((string) $attendee['responseStatus'])
                    : null;
            }
        }

        return null;
    }

    /**
     * Converte o payload unificado da aplicação para o formato do Google.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function toGooglePayload(array $attributes): array
    {
        $allDay = (bool) ($attributes['all_day'] ?? false);
        $start = CarbonImmutable::parse($attributes['start']);
        $end = CarbonImmutable::parse($attributes['end']);

        $payload = [
            'summary' => (string) $attributes['title'],
            'description' => (string) ($attributes['description'] ?? ''),
            'location' => (string) ($attributes['location'] ?? ''),
            // All-day no Google é `date` (sem hora); timed event é `dateTime` (RFC 3339).
            'start' => $allDay
                ? ['date' => $start->toDateString(), 'timeZone' => 'UTC']
                : ['dateTime' => $start->toIso8601String()],
            'end' => $allDay
                ? ['date' => $end->toDateString(), 'timeZone' => 'UTC']
                : ['dateTime' => $end->toIso8601String()],
        ];

        if (isset($attributes['attendees'])) {
            $payload['attendees'] = array_map(
                static fn (string $email): array => ['email' => $email],
                (array) $attributes['attendees'],
            );
        }

        if (isset($attributes['visibility'])) {
            $payload['visibility'] = (string) $attributes['visibility'];
        }

        return $payload;
    }
}
