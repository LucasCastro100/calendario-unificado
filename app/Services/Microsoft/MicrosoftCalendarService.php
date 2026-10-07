<?php

declare(strict_types=1);

namespace App\Services\Microsoft;

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
 * Adapter do Microsoft Graph v1.0 (calendários do Outlook / Microsoft 365).
 *
 * Diferenças em relação ao Google tratadas aqui: `isAllDay` é flag booleana,
 * cancelamento vem em `isCancelled` (não em `status`), a paginação usa
 * `@odata.nextLink` absoluto e o fuso é negociado pelo header `Prefer`.
 */
final class MicrosoftCalendarService extends AbstractCalendarService
{
    private const BASE_URL = 'https://graph.microsoft.com/v1.0';

    private const PAGE_SIZE = 100;

    /**
     * Teto de páginas por requisição. Acima disso a resposta é truncada em vez
     * de consumir toda a cota da API do usuário.
     */
    private const DAY_IN_SECONDS = 86400;

    public function provider(): OauthProvider
    {
        return OauthProvider::Microsoft;
    }

    /**
     * {@inheritDoc}
     */
    public function listRequest(CalendarConnection $connection, CalendarRange $range): array
    {
        $calendarId = $connection->calendar_ids[0] ?? 'me';

        return [
            'url' => self::BASE_URL.'/me/calendars/'.urlencode($calendarId).'/calendarView',
            'query' => [
                'startDateTime' => $range->start->toIso8601String(),
                'endDateTime' => $range->end->toIso8601String(),
                '$top' => self::PAGE_SIZE,
                '$orderby' => 'start/dateTime',
            ],
            'headers' => [
                // Faz o Graph devolver as datas já convertidas para o fuso do
                // range, evitando deslocamento de 1 dia em evento all-day.
                'Prefer' => 'outlook.timezone="'.$range->start->timezone->getName().'"',
            ],
        ];
    }

    /**
     * O Graph devolve o `@odata.nextLink` já absoluto, então a spec da próxima
     * página vem inteira do payload — o `url` do header é ignorado.
     *
     * @param  array<string, mixed>  $spec
     * @return array{url: string, query: array<string, mixed>, headers: array<string, string>}
     */
    public function nextRequest(array $spec, string $nextLink): array
    {
        return [
            'url' => $nextLink,
            'query' => [],
            'headers' => $spec['headers'] ?? [],
        ];
    }

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
        $events = collect($payload['value'] ?? [])->map(
            fn (array $item): ?UnifiedEventDTO => $this->toDto($item, $connection),
        )->filter()->values();

        $nextLink = $payload['@odata.nextLink'] ?? null;

        return new EventPageDTO(
            events: $events,
            nextRequest: is_string($nextLink) && $nextLink !== ''
                ? $this->nextRequest($spec, $nextLink)
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
            self::BASE_URL.'/me/events',
            $this->toGraphPayload($attributes),
        );

        if (! $response->successful()) {
            ProviderFailureHandler::handle($response, OauthProvider::Microsoft);
        }

        return $this->toDto($response->json() ?? [], $connection)
            ?? throw CalendarProviderException::requestFailed(OauthProvider::Microsoft, 'Resposta sem evento');
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws CalendarProviderException
     */
    public function updateEvent(User $user, CalendarConnection $connection, string $eventId, array $attributes): UnifiedEventDTO
    {
        $response = $this->client($connection)->patch(
            self::BASE_URL.'/me/events/'.urlencode($eventId),
            $this->toGraphPayload($attributes),
        );

        if (! $response->successful()) {
            ProviderFailureHandler::handle($response, OauthProvider::Microsoft);
        }

        return $this->toDto($response->json() ?? [], $connection)
            ?? throw CalendarProviderException::requestFailed(OauthProvider::Microsoft, 'Resposta sem evento');
    }

    /**
     * @throws CalendarProviderException
     */
    public function deleteEvent(User $user, CalendarConnection $connection, string $eventId): void
    {
        $response = $this->client($connection)->delete(
            self::BASE_URL.'/me/events/'.urlencode($eventId),
        );

        // 404 = já removido. Idempotente, então não é erro.
        if (! $response->successful() && $response->status() !== 404) {
            ProviderFailureHandler::handle($response, OauthProvider::Microsoft);
        }
    }

    /**
     * @return Collection<int, array{id: string, name: string, primary: bool}>
     *
     * @throws CalendarProviderException
     */
    public function calendars(User $user, CalendarConnection $connection): Collection
    {
        $calendars = collect();
        $url = self::BASE_URL.'/me/calendars?$top='.self::PAGE_SIZE;
        $pages = 0;

        while ($url !== null && $pages < self::MAX_PAGES) {
            $response = $this->client($connection)->get($url);

            if (! $response->successful()) {
                ProviderFailureHandler::handle($response, OauthProvider::Microsoft);
            }

            foreach ($response->json('value', []) ?? [] as $item) {
                $calendars->push([
                    'id' => (string) $item['id'],
                    'name' => (string) ($item['name'] ?? $item['id']),
                    'primary' => (bool) ($item['isDefaultCalendar'] ?? false),
                ]);
            }

            $next = $response->json('@odata.nextLink');
            $url = is_string($next) ? $next : null;
            $pages++;
        }

        return $calendars->values();
    }

    private function client(CalendarConnection $connection): PendingRequest
    {
        return Http::withToken((string) $connection->access_token)
            ->acceptJson()
            ->timeout(15)
            ->retry(2, 250, throw: false);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function toDto(array $item, CalendarConnection $connection): ?UnifiedEventDTO
    {
        $remoteId = $item['id'] ?? null;

        if ($remoteId === null) {
            return null;
        }

        $allDay = (bool) ($item['isAllDay'] ?? false);
        $start = $this->parseMoment($item['start'] ?? null, $allDay);

        if ($start === null) {
            return null;
        }

        $end = $this->parseMoment($item['end'] ?? null, $allDay) ?? $start->addSeconds(self::DAY_IN_SECONDS);

        $attendees = collect($item['attendees'] ?? [])->map(
            static fn (array $attendee): UnifiedAttendeeDTO => UnifiedAttendeeDTO::fromArray($attendee),
        )->values()->all();

        return new UnifiedEventDTO(
            uid: UnifiedEventDTO::makeUid(OauthProvider::Microsoft, (string) $remoteId),
            provider: OauthProvider::Microsoft,
            remoteId: (string) $remoteId,
            calendarId: (string) ($item['calendarId'] ?? ($connection->calendar_ids[0] ?? 'me')),
            title: (string) ($item['subject'] ?? '(sem título)'),
            description: UnifiedEventDTO::sanitizeText($item['body']['content'] ?? null),
            location: UnifiedEventDTO::sanitizeText($item['location']['displayName'] ?? null),
            start: $start,
            end: $end,
            allDay: $allDay,
            status: $this->mapStatus($item),
            visibility: $this->mapVisibility($item['sensitivity'] ?? null),
            organizer: isset($item['organizer'])
                ? UnifiedAttendeeDTO::fromArray($item['organizer'] + ['isOrganizer' => true])
                : null,
            attendees: $attendees,
            webLink: UnifiedEventDTO::sanitizeUrl($item['webLink'] ?? null),
            // A Graph entrega a call em `onlineMeeting.joinUrl`; `joinWebUrl`
            // é o equivalente pelo navegador. Preferimos o primeiro.
            meetingLink: UnifiedEventDTO::sanitizeUrl(
                $item['onlineMeeting']['joinUrl']
                    ?? $item['onlineMeeting']['joinWebUrl']
                    ?? null,
            ),
            responseStatus: isset($item['responseStatus']['response'])
                ? strtolower((string) $item['responseStatus']['response'])
                : null,
        );
    }

    /**
     * O Graph sinaliza cancelamento por flag booleana, não por `status`.
     *
     * @param  array<string, mixed>  $item
     */
    private function mapStatus(array $item): EventStatus
    {
        if (($item['isCancelled'] ?? false) === true) {
            return EventStatus::Cancelled;
        }

        $response = $item['responseStatus']['response'] ?? null;

        return $response === 'tentative' ? EventStatus::Tentative : EventStatus::Confirmed;
    }

    /**
     * O Graph usa `sensitivity` (`normal` | `private` | `personal`), vocabulário
     * diferente do `visibility` do Google — traduzido aqui.
     */
    private function mapVisibility(?string $sensitivity): EventVisibility
    {
        return match ($sensitivity) {
            'private', 'personal' => EventVisibility::Private,
            default => EventVisibility::Default,
        };
    }

    /**
     * All-day no Graph vem sem hora (`2026-01-15T00:00:00.0000000`); o Carbon
     * não parseia os 7 dígitos de fração de segundo, então truncamos.
     *
     * @param  array<string, mixed>|null  $moment
     */
    private function parseMoment(?array $moment, bool $allDay): ?CarbonImmutable
    {
        $value = $moment['dateTime'] ?? null;

        if ($value === null) {
            return null;
        }

        $normalized = (string) $value;

        if (preg_match('/\.\d{7}$/', $normalized) === 1) {
            $normalized = substr($normalized, 0, -2);
        }

        try {
            $parsed = CarbonImmutable::parse($normalized);

            return $allDay ? $parsed->startOfDay() : $parsed;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function toGraphPayload(array $attributes): array
    {
        $allDay = (bool) ($attributes['all_day'] ?? false);
        $start = CarbonImmutable::parse($attributes['start']);
        $end = CarbonImmutable::parse($attributes['end']);

        $payload = [
            'subject' => (string) $attributes['title'],
            // O Graph aceita HTML no body; escapamos porque a origem é texto
            // puro vindo do DTO, e não queremos re-injetar marcação.
            'body' => [
                'contentType' => 'HTML',
                'content' => htmlspecialchars((string) ($attributes['description'] ?? ''), ENT_QUOTES, 'UTF-8'),
            ],
            'start' => [
                'dateTime' => $start->format('Y-m-d\TH:i:s'),
                'timeZone' => $start->timezone->getName(),
            ],
            'end' => [
                'dateTime' => $end->format('Y-m-d\TH:i:s'),
                'timeZone' => $end->timezone->getName(),
            ],
            'isAllDay' => $allDay,
        ];

        if (isset($attributes['location'])) {
            $payload['location'] = ['displayName' => (string) $attributes['location']];
        }

        if (isset($attributes['attendees'])) {
            $payload['attendees'] = array_map(
                static fn (string $email): array => [
                    'emailAddress' => ['address' => $email],
                    'type' => 'required',
                ],
                (array) $attributes['attendees'],
            );
        }

        if (isset($attributes['visibility'])) {
            $payload['sensitivity'] = $attributes['visibility'] === 'private' ? 'private' : 'normal';
        }

        return $payload;
    }
}
