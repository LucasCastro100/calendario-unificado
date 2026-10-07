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
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Comportamento do endpoint agregado de eventos.
 *
 * O foco é o contrato com o frontend: envelope JSON, semântica de falha parcial
 * (`meta.failed_providers`) e ausência de credenciais na resposta.
 */
final class CalendarEventsEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const START = '2026-09-28T00:00:00Z';

    private const END = '2026-10-05T00:00:00Z';

    public function test_exige_autenticacao(): void
    {
        $this->getJson('/api/calendar/events')->assertUnauthorized();
    }

    public function test_agrega_eventos_de_todos_os_providers_conectados(): void
    {
        $user = User::factory()->create();
        $this->connected($user, OauthProvider::Google);
        $this->connected($user, OauthProvider::Microsoft);

        $this->fakeAdapters([
            'google' => $this->page('google', 'Reunião Google', '2026-09-29T10:00:00Z'),
            'microsoft' => $this->page('microsoft', 'Reunião Microsoft', '2026-09-29T12:00:00Z'),
        ]);

        $response = $this->actingAs($user)->getJson($this->url());

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.failed_providers', []);

        $this->assertSame(
            ['Reunião Google', 'Reunião Microsoft'],
            array_column($response->json('data'), 'title'),
        );
    }

    public function test_ordena_eventos_por_inicio(): void
    {
        $user = User::factory()->create();
        $this->connected($user, OauthProvider::Google);

        $this->fakeAdapters([
            'google' => $this->page('google', 'Depois', '2026-09-30T15:00:00Z'),
        ]);

        $response = $this->actingAs($user)->getJson($this->url());

        $response->assertOk();
        $this->assertSame('Depois', $response->json('data.0.title'));
    }

    public function test_falha_de_um_provider_nao_esconde_os_eventos_do_outro(): void
    {
        $user = User::factory()->create();
        $this->connected($user, OauthProvider::Google);
        $this->connected($user, OauthProvider::Microsoft);

        $this->fakeAdapters([
            'google' => $this->page('google', 'Sobreviveu', '2026-09-29T10:00:00Z'),
        ], ['microsoft' => 401]);

        $response = $this->actingAs($user)->getJson($this->url());

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Sobreviveu')
            ->assertJsonPath('meta.failed_providers', ['microsoft']);
    }

    public function test_nao_expoe_tokens_de_acesso_ou_refresh(): void
    {
        $user = User::factory()->create();
        $this->connected($user, OauthProvider::Google);

        $this->fakeAdapters([
            'google' => $this->page('google', 'Privado', '2026-09-29T10:00:00Z'),
        ]);

        $body = $this->actingAs($user)->getJson($this->url())
            ->assertOk()
            ->content();

        $this->assertStringNotContainsString('fake-access-token', $body);
        $this->assertStringNotContainsString('fake-refresh-token', $body);
        $this->assertStringNotContainsString('access_token', $body);
    }

    public function test_ignora_conexoes_de_outros_usuarios(): void
    {
        $user = User::factory()->create();
        CalendarConnection::factory()->create([
            'user_id' => User::factory()->create()->id,
            'provider' => OauthProvider::Google,
        ]);

        $this->fakeAdapters([]);

        $this->actingAs($user)->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.failed_providers', []);
    }

    public function test_ignora_conexao_com_escopo_revogado(): void
    {
        $user = User::factory()->create();
        $this->connected($user, OauthProvider::Google, revoked: true);

        $this->fakeAdapters([]);

        $this->actingAs($user)->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_filtra_por_providers_solicitados(): void
    {
        $user = User::factory()->create();
        $this->connected($user, OauthProvider::Google);
        $this->connected($user, OauthProvider::Microsoft);

        $this->fakeAdapters([
            'microsoft' => $this->page('microsoft', 'Só Microsoft', '2026-09-29T12:00:00Z'),
        ]);

        $this->actingAs($user)->getJson($this->url('&providers[]=microsoft'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Só Microsoft');
    }

    public function test_rejeita_intervalo_invertido(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/calendar/events?start_date=2026-10-05T00:00:00Z&end_date='.self::START)
            ->assertStatus(422)
            ->assertJsonValidationErrors('end_date');
    }

    public function test_rejeita_provider_desconhecido(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson($this->url('&providers[]=yahoo'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('providers.0');
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function url(string $extra = ''): string
    {
        return '/api/calendar/events?start_date='.self::START.'&end_date='.self::END.$extra;
    }

    private function connected(User $user, OauthProvider $provider, bool $revoked = false): CalendarConnection
    {
        return CalendarConnection::factory()->create([
            'user_id' => $user->id,
            'provider' => $provider,
            'scopes_revoked_at' => $revoked ? now() : null,
        ]);
    }

    /**
     * Substitui os adapters por dublês, mantendo o `Http::pool()` real.
     *
     * Usa `bind()` e não `instance()`: `Container::resolve()` ignora as
     * instâncias registradas sempre que a resolução recebe parâmetros, e o
     * agregador sempre resolve com `['provider' => ...]`. Com `instance()` o
     * container cairia no binding real e o teste faria I/O de verdade.
     *
     * O `OAuthTokenRefresher` não é mockado: a factory grava token válido por
     * uma hora, então `needsRefresh()` é falso e o refresher retorna sem tocar
     * a rede.
     *
     * @param  array<string, EventPageDTO>  $pages  Página por valor de provider.
     * @param  array<string, int>  $status  Status HTTP por provider; ausente = 200.
     */
    private function fakeAdapters(array $pages, array $status = []): void
    {
        $mocks = [];
        $stubs = [];

        foreach (OauthProvider::cases() as $provider) {
            $key = $provider->value;

            $stubs[$key.'.test/*'] = Http::response(
                ['error' => ['message' => 'token revogado']],
                $status[$key] ?? 200,
            );

            $mock = Mockery::mock(CalendarServiceInterface::class);
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

    private function page(string $provider, string $title, string $start): EventPageDTO
    {
        return new EventPageDTO(
            events: new Collection([
                new UnifiedEventDTO(
                    uid: UnifiedEventDTO::makeUid(OauthProvider::from($provider), 'evt-1'),
                    provider: OauthProvider::from($provider),
                    remoteId: 'evt-1',
                    title: $title,
                    start: CarbonImmutable::parse($start),
                    end: CarbonImmutable::parse($start)->addHour(),
                    calendarId: 'primary',
                ),
            ]),
            nextRequest: null,
        );
    }
}
