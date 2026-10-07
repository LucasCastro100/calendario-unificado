<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Calendar;

use App\Enums\OauthProvider;
use App\Models\CalendarConnection;
use App\Services\Google\GoogleCalendarService;
use App\Services\Microsoft\MicrosoftCalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Normalização dos payloads reais dos dois providers.
 *
 * Usa os adapters de verdade (sem mock de I/O): o que está em jogo aqui é o
 * contrato do DTO, que é o que o frontend consome. Qualquer mudança no formato
 * de um terceiro que quebrar o mapeamento quebra aqui.
 */
final class CalendarAdaptersParsingTest extends TestCase
{
    use RefreshDatabase;

    private const SPEC = ['url' => 'https://api.test', 'query' => [], 'headers' => []];

    // ── Google ─────────────────────────────────────────────────────────────

    public function test_google_normaliza_evento_com_horario(): void
    {
        $page = $this->google()->parsePage([
            'items' => [[
                'id' => 'evt-1',
                'summary' => 'Weekly sync',
                'description' => 'Pauta da semana',
                'location' => 'Sala 3',
                'start' => ['dateTime' => '2026-09-29T10:00:00-03:00'],
                'end' => ['dateTime' => '2026-09-29T11:00:00-03:00'],
                'status' => 'confirmed',
                'htmlLink' => 'https://calendar.google.com/event?eid=abc',
            ]],
        ], $this->connection(OauthProvider::Google), self::SPEC);

        $event = $page->events->first();

        $this->assertNotNull($event);
        $this->assertSame('google:evt-1', $event->uid);
        $this->assertSame('google', $event->provider->value);
        $this->assertSame('Weekly sync', $event->title);
        $this->assertSame('Sala 3', $event->location);
        $this->assertFalse($event->allDay);
        $this->assertSame('2026-09-29T10:00:00-03:00', $event->start->format('c'));
        $this->assertSame('2026-09-29T11:00:00-03:00', $event->end->format('c'));
    }

    public function test_google_trata_evento_de_dia_inteiro(): void
    {
        $page = $this->google()->parsePage([
            'items' => [[
                'id' => 'evt-all-day',
                'summary' => 'Feriado',
                'start' => ['date' => '2026-10-01'],
                'end' => ['date' => '2026-10-02'],
            ]],
        ], $this->connection(OauthProvider::Google), self::SPEC);

        $event = $page->events->first();

        $this->assertNotNull($event);
        $this->assertTrue($event->allDay);
        $this->assertSame('2026-10-01', $event->start->format('Y-m-d'));
    }

    public function test_google_normaliza_evento_cancelado(): void
    {
        $page = $this->google()->parsePage([
            'items' => [[
                'id' => 'evt-cancelled',
                'summary' => 'Removido',
                'status' => 'cancelled',
                'start' => ['dateTime' => '2026-09-29T10:00:00Z'],
                'end' => ['dateTime' => '2026-09-29T11:00:00Z'],
            ]],
        ], $this->connection(OauthProvider::Google), self::SPEC);

        $event = $page->events->first();

        $this->assertNotNull($event);
        $this->assertTrue($event->isCancelled());
    }

    public function test_google_usa_titulo_padrao_quando_resumo_ausente(): void
    {
        $page = $this->google()->parsePage([
            'items' => [[
                'id' => 'evt-no-summary',
                'start' => ['dateTime' => '2026-09-29T10:00:00Z'],
                'end' => ['dateTime' => '2026-09-29T11:00:00Z'],
            ]],
        ], $this->connection(OauthProvider::Google), self::SPEC);

        $this->assertSame('(sem título)', $page->events->first()?->title);
    }

    public function test_google_descarta_item_sem_id_ou_sem_inicio(): void
    {
        $page = $this->google()->parsePage([
            'items' => [
                ['summary' => 'Sem id', 'start' => ['dateTime' => '2026-09-29T10:00:00Z']],
                ['id' => 'sem-inicio', 'summary' => 'Sem início'],
                ['id' => 'valido', 'summary' => 'Ok', 'start' => ['dateTime' => '2026-09-29T10:00:00Z']],
            ],
        ], $this->connection(OauthProvider::Google), self::SPEC);

        $this->assertCount(1, $page->events);
        $this->assertSame('Ok', $page->events->first()?->title);
    }

    public function test_google_expoe_proxima_pagina_via_page_token(): void
    {
        $page = $this->google()->parsePage([
            'items' => [],
            'nextPageToken' => 'tok-2',
        ], $this->connection(OauthProvider::Google), self::SPEC);

        $this->assertTrue($page->hasNextPage());
        $this->assertNotNull($page->nextRequest);
    }

    public function test_google_sem_page_token_nao_avanca(): void
    {
        $page = $this->google()->parsePage(['items' => []], $this->connection(OauthProvider::Google), self::SPEC);

        $this->assertFalse($page->hasNextPage());
        $this->assertNull($page->nextRequest);
    }

    // ── Microsoft Graph ────────────────────────────────────────────────────

    public function test_microsoft_normaliza_evento_com_horario(): void
    {
        $page = $this->microsoft()->parsePage([
            'value' => [[
                'id' => 'AAMkAG',
                'subject' => 'Design review',
                'bodyPreview' => 'Revisar o layout',
                'location' => ['displayName' => 'Sala 5'],
                'start' => ['dateTime' => '2026-09-29T14:00:00', 'timeZone' => 'E. South America Standard Time'],
                'end' => ['dateTime' => '2026-09-29T15:00:00', 'timeZone' => 'E. South America Standard Time'],
                'showAs' => 'busy',
                'isCancelled' => false,
                'webLink' => 'https://outlook.office.com/calendar/item/AAMkAG',
            ]],
        ], $this->connection(OauthProvider::Microsoft), self::SPEC);

        $event = $page->events->first();

        $this->assertNotNull($event);
        $this->assertSame('microsoft:AAMkAG', $event->uid);
        $this->assertSame('microsoft', $event->provider->value);
        $this->assertSame('Design review', $event->title);
        $this->assertSame('Sala 5', $event->location);
        $this->assertFalse($event->allDay);
    }

    public function test_microsoft_trata_evento_de_dia_inteiro(): void
    {
        $page = $this->microsoft()->parsePage([
            'value' => [[
                'id' => 'all-day-1',
                'subject' => 'Férias',
                'isAllDay' => true,
                'start' => ['dateTime' => '2026-10-01T00:00:00', 'timeZone' => 'UTC'],
                'end' => ['dateTime' => '2026-10-02T00:00:00', 'timeZone' => 'UTC'],
            ]],
        ], $this->connection(OauthProvider::Microsoft), self::SPEC);

        $event = $page->events->first();

        $this->assertNotNull($event);
        $this->assertTrue($event->allDay);
    }

    public function test_microsoft_expoe_proxima_pagina_via_next_link(): void
    {
        $page = $this->microsoft()->parsePage([
            'value' => [],
            '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/me/calendarView?$skiptoken=abc',
        ], $this->connection(OauthProvider::Microsoft), self::SPEC);

        $this->assertTrue($page->hasNextPage());
        $this->assertSame(
            'https://graph.microsoft.com/v1.0/me/calendarView?$skiptoken=abc',
            $page->nextRequest['url'] ?? null,
        );
    }

    public function test_microsoft_normaliza_evento_cancelado(): void
    {
        $page = $this->microsoft()->parsePage([
            'value' => [[
                'id' => 'cancelled-1',
                'subject' => 'Cancelado',
                'isCancelled' => true,
                'start' => ['dateTime' => '2026-09-29T14:00:00', 'timeZone' => 'UTC'],
                'end' => ['dateTime' => '2026-09-29T15:00:00', 'timeZone' => 'UTC'],
            ]],
        ], $this->connection(OauthProvider::Microsoft), self::SPEC);

        $this->assertTrue($page->events->first()?->isCancelled());
    }

    public function test_microsoft_aceita_payload_vazio_sem_quebrar(): void
    {
        $page = $this->microsoft()->parsePage([], $this->connection(OauthProvider::Microsoft), self::SPEC);

        $this->assertTrue($page->events->isEmpty());
        $this->assertFalse($page->hasNextPage());
    }

    /**
     * Invariante que vale para os dois providers: o mesmo evento nos dois
     * calendars precisa de UIDs distintos, senão o `unique('uid')` da agregação
     * descartaria um deles.
     */
    public function test_o_mesmo_evento_gera_uids_distintos_por_provider(): void
    {
        $payload = [
            'items' => [[
                'id' => 'same-id',
                'summary' => 'Mesmo id',
                'start' => ['dateTime' => '2026-09-29T10:00:00Z'],
                'end' => ['dateTime' => '2026-09-29T11:00:00Z'],
            ]],
        ];

        $google = $this->google()->parsePage($payload, $this->connection(OauthProvider::Google), self::SPEC);
        $microsoft = $this->microsoft()->parsePage(
            ['value' => [[
                'id' => 'same-id',
                'subject' => 'Mesmo id',
                'start' => ['dateTime' => '2026-09-29T10:00:00', 'timeZone' => 'UTC'],
                'end' => ['dateTime' => '2026-09-29T11:00:00', 'timeZone' => 'UTC'],
            ]]],
            $this->connection(OauthProvider::Microsoft),
            self::SPEC,
        );

        $this->assertNotSame(
            $google->events->first()?->uid,
            $microsoft->events->first()?->uid,
        );
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function google(): GoogleCalendarService
    {
        return new GoogleCalendarService;
    }

    private function microsoft(): MicrosoftCalendarService
    {
        return new MicrosoftCalendarService;
    }

    private function connection(OauthProvider $provider): CalendarConnection
    {
        return CalendarConnection::factory()->make([
            'provider' => $provider,
            'calendar_ids' => ['primary'],
        ]);
    }
}
