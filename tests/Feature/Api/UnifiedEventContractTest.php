<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\DTOs\Calendar\EventPageDTO;
use App\DTOs\Calendar\UnifiedEventDTO;
use App\Enums\OauthProvider;
use App\Models\CalendarConnection;
use App\Models\User;
use App\Services\Contracts\CalendarServiceInterface;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Contrato público do recurso: chaves em snake_case, `meeting_link`/`color`
 * presentes e degradação quando o token de um provider não é renovável.
 */
final class UnifiedEventContractTest extends TestCase
{
    use RefreshDatabase;

    private const START = '2026-09-28T00:00:00Z';

    private const END = '2026-10-05T00:00:00Z';

    public function test_expoe_o_contrato_publico_em_snake_case(): void
    {
        $user = User::factory()->create();
        $this->connected($user, OauthProvider::Google);

        $this->fakeAdapters([
            'google' => $this->page(
                OauthProvider::Google,
                'Daily',
                'https://meet.google.com/abc-defg-hij',
            ),
        ]);

        $event = $this->actingAs($user)->getJson($this->url())->assertOk()->json('data.0');

        $this->assertSame('Daily', $event['title']);
        $this->assertSame('google', $event['provider']);
        $this->assertSame('2026-09-29T10:00:00+00:00', $event['start_time']);
        $this->assertSame('2026-09-29T11:00:00+00:00', $event['end_time']);
        $this->assertSame('https://meet.google.com/abc-defg-hij', $event['meeting_link']);
        $this->assertSame('#4285F4', $event['color']);

        // Nenhuma chave interna em camelCase vaza para o cliente.
        $this->assertSame(
            [],
            array_intersect(
                ['startTime', 'endTime', 'meetingLink', 'remoteId', 'calendarId', 'allDay', 'webLink'],
                array_keys($event),
            ),
        );
    }

    public function test_cor_dos_providers_segue_a_identidade_da_marca(): void
    {
        $user = User::factory()->create();
        $this->connected($user, OauthProvider::Google);
        $this->connected($user, OauthProvider::Microsoft);

        $this->fakeAdapters([
            'google' => $this->page(OauthProvider::Google, 'G'),
            'microsoft' => $this->page(OauthProvider::Microsoft, 'M'),
        ]);

        $data = $this->actingAs($user)->getJson($this->url())->assertOk()->json('data');

        $this->assertSame(
            ['#4285F4', '#6264A7'],
            array_column($data, 'color'),
        );
    }

    public function test_provider_com_refresh_rejeitado_nao_esconde_os_eventos_do_outro(): void
    {
        $user = User::factory()->create();
        $this->connected($user, OauthProvider::Google);
        $this->connected($user, OauthProvider::Microsoft, stale: true);

        $this->fakeAdapters([
            'google' => $this->page(OauthProvider::Google, 'Sobreviveu'),
        ]);
        $this->fakeRefreshRejected();

        $response = $this->actingAs($user)->getJson($this->url());

        // A Microsoft exige reconexão, mas isso não pode apagar o Google: a
        // listagem unificada degrada de forma parcial.
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Sobreviveu')
            ->assertJsonPath('meta.failed_providers', ['microsoft']);
    }

    public function test_todos_os_providers_com_refresh_rejeitado_ainda_respondem_200(): void
    {
        $user = User::factory()->create();
        $this->connected($user, OauthProvider::Google, stale: true);
        $this->connected($user, OauthProvider::Microsoft, stale: true);

        $this->fakeAdapters([]);
        $this->fakeRefreshRejected();

        $this->actingAs($user)->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.failed_providers', ['google', 'microsoft']);
    }

    public function test_rota_de_provider_unico_propaga_o_erro_de_reconexao(): void
    {
        $user = User::factory()->create();
        // Conexão revogada: o middleware não tem resposta parcial a dar, então
        // o erro chega ao cliente como 422 em vez de sumir da lista.
        CalendarConnection::factory()->create([
            'user_id' => $user->id,
            'provider' => OauthProvider::Microsoft,
            'scopes_revoked_at' => now(),
        ]);

        $this->fakeAdapters([]);

        $this->actingAs($user)
            ->getJson('/api/calendar/microsoft/events?start_date='.self::START.'&end_date='.self::END)
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROVIDER_REAUTH_REQUIRED')
            ->assertJsonPath('provider', 'microsoft');
    }

    public function test_get_events_devolve_eventos_de_um_provider(): void
    {
        $user = User::factory()->create();
        $this->connected($user, OauthProvider::Google);

        $this->fakeAdapters([
            'google' => $this->page(OauthProvider::Google, 'Via interface'),
        ]);

        $this->actingAs($user);

        $service = app(CalendarServiceInterface::class, ['provider' => OauthProvider::Google]);

        $events = $service->getEvents(OauthProvider::Google, self::START, self::END);

        $this->assertCount(1, $events);
        $this->assertInstanceOf(UnifiedEventDTO::class, $events[0]);
        $this->assertSame('Via interface', $events[0]->title);
    }

    public function test_get_events_sem_conexao_devolve_lista_vazia(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->fakeAdapters([]);

        $service = app(CalendarServiceInterface::class, ['provider' => OauthProvider::Google]);

        // Sem conexão não é erro: o usuário só não conectou esta conta.
        $this->assertSame([], $service->getEvents(OauthProvider::Google, self::START, self::END));
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function url(): string
    {
        return '/api/calendar/events?start_date='.self::START.'&end_date='.self::END;
    }

    /**
     * @param  bool  $stale  Access token vencido, para forçar a renovação.
     */
    private function connected(User $user, OauthProvider $provider, bool $stale = false): CalendarConnection
    {
        return CalendarConnection::factory()->create([
            'user_id' => $user->id,
            'provider' => $provider,
            'token_expires_at' => $stale ? now()->subMinute() : now()->addHour(),
            'refresh_token' => 'fake-refresh-token',
        ]);
    }

    /**
     * Os dois provedores recusam a renovação: é o sinal de que o consentimento
     * foi revogado e o usuário precisa reconectar.
     */
    private function fakeRefreshRejected(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400),
            'login.microsoftonline.com/*' => Http::response(['error' => 'invalid_grant'], 400),
        ]);
    }

    /**
     * @param  array<string, EventPageDTO>  $pages
     */
    private function fakeAdapters(array $pages): void
    {
        $mocks = [];
        $stubs = [];

        foreach (OauthProvider::cases() as $provider) {
            $key = $provider->value;

            $stubs[$key.'.test/*'] = Http::response(['value' => []]);

            $mock = Mockery::mock(CalendarServiceInterface::class);
            $mock->shouldReceive('provider')->andReturn($provider);
            $mock->shouldReceive('listRequest')->andReturn([
                'url' => 'https://'.$key.'.test/events',
                'query' => [],
                'headers' => [],
            ]);
            $mock->shouldReceive('prepareRequest')->andReturnUsing(
                static fn (PendingRequest $request): PendingRequest => $request->timeout(5),
            );
            $mock->shouldReceive('parsePage')->andReturn(
                $pages[$key] ?? new EventPageDTO(events: new Collection, nextRequest: null),
            );
            $mock->shouldReceive('getEvents')->byDefault()->andReturn(
                ($pages[$key] ?? null)?->events->all() ?? [],
            );
            $mock->shouldReceive('events')->byDefault()->andReturn(
                ($pages[$key] ?? null)?->events ?? new Collection,
            );

            $mocks[$key] = $mock;
        }

        $this->app->bind(
            CalendarServiceInterface::class,
            static function ($app, array $parameters) use ($mocks): CalendarServiceInterface {
                /** @var OauthProvider $provider */
                $provider = $parameters['provider'];

                return $mocks[$provider->value];
            },
        );

        Http::fake($stubs);
    }

    private function page(OauthProvider $provider, string $title, ?string $meetingLink = null): EventPageDTO
    {
        $start = CarbonImmutable::parse('2026-09-29T10:00:00Z');

        return new EventPageDTO(
            events: new Collection([
                new UnifiedEventDTO(
                    uid: UnifiedEventDTO::makeUid($provider, 'evt-1'),
                    provider: $provider,
                    remoteId: 'evt-1',
                    title: $title,
                    start: $start,
                    end: $start->addHour(),
                    calendarId: 'primary',
                    meetingLink: $meetingLink,
                ),
            ]),
            nextRequest: null,
        );
    }
}
