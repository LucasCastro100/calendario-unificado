<?php

declare(strict_types=1);

namespace App\DTOs\Calendar;

use App\Enums\EventStatus;
use App\Enums\EventVisibility;
use App\Enums\OauthProvider;
use Carbon\CarbonImmutable;

/**
 * Contrato único de evento de calendário entre os providers e o cliente.
 *
 * Os adapters (Google/Microsoft) traduzem o payload bruto do provedor para este
 * DTO, de modo que o frontend nunca conhece o formato de um terceiro.
 * `toArray()` é a única forma de serialização — nunca expor model Eloquent.
 *
 * A serialização é em `snake_case` porque é o contrato público consumido pelo
 * Next.js (ver `web/src/types/calendar.ts`). As propriedades em camelCase
 * seguem a convenção interna do PHP; a conversão acontece só na fronteira.
 */
final readonly class UnifiedEventDTO
{
    /**
     * @param  array<int, UnifiedAttendeeDTO>  $attendees
     */
    public function __construct(
        public string $uid,
        public OauthProvider $provider,
        public string $remoteId,
        public string $title,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public ?string $calendarId = null,
        public ?string $description = null,
        public ?string $location = null,
        /**
         * Link de videoconferência: `hangoutLink` do Google Meet ou
         * `onlineMeeting.joinUrl` do Teams. Sanitizado — só http/https.
         */
        public ?string $meetingLink = null,
        public bool $allDay = false,
        public EventStatus $status = EventStatus::Confirmed,
        public EventVisibility $visibility = EventVisibility::Default,
        public ?UnifiedAttendeeDTO $organizer = null,
        public array $attendees = [],
        public ?string $webLink = null,
        public ?string $responseStatus = null,
    ) {
        // Invariante do contrato: o fim nunca precede o início. Eventos all-day
        // do Google às vezes trazem fim no dia seguinte; normalizado aqui para
        // que o FullCalendar não renderize duração negativa.
        if ($end->lessThan($start)) {
            throw new \InvalidArgumentException('A data de término do evento não pode ser anterior à de início.');
        }
    }

    /**
     * UID estável: o mesmo evento sempre produz a mesma chave, o que permite
     * deduplicar na agregação e usar como `key` do React sem colisão entre
     * providers.
     */
    public static function makeUid(OauthProvider $provider, string $remoteId): string
    {
        return $provider->value.':'.$remoteId;
    }

    public function isCancelled(): bool
    {
        return $this->status === EventStatus::Cancelled;
    }

    /**
     * O evento tem um link de reunião utilizável?
     */
    public function hasMeetingLink(): bool
    {
        return $this->meetingLink !== null;
    }

    /**
     * Texto puro: a descrição chega como HTML arbitrário de terceiro e nunca deve
     * chegar ao cliente como HTML (seria vetor de XSS no front).
     */
    public static function sanitizeText(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $text = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text) ?: null;
    }

    /**
     * Bloqueia esquema perigoso (javascript:, data:) antes do valor virar href no front.
     */
    public static function sanitizeUrl(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $url = trim($value);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $url : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->uid,
            'provider' => $this->provider->value,
            'title' => $this->title,
            'description' => $this->description,
            'start_time' => $this->start->toIso8601String(),
            'end_time' => $this->end->toIso8601String(),
            'meeting_link' => $this->meetingLink,
            'location' => $this->location,
            'color' => $this->provider->color(),
            'remote_id' => $this->remoteId,
            'calendar_id' => $this->calendarId,
            'all_day' => $this->allDay,
            'status' => $this->status->value,
            'visibility' => $this->visibility->value,
            'web_link' => $this->webLink,
            'response_status' => $this->responseStatus,
            'organizer' => $this->organizer?->toArray(),
            'attendees' => array_map(
                static fn (UnifiedAttendeeDTO $attendee): array => $attendee->toArray(),
                $this->attendees,
            ),
        ];
    }
}
