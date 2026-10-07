<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\OauthProvider;
use App\Exceptions\CalendarProviderException;
use App\Models\CalendarConnection;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regras de segurança não negociáveis.
 *
 * Cada teste aqui existe porque a falha correspondente vazaria credencial ou
 * detalhe interno para fora. Ver `.opencode/memory/security-rules.md`.
 */
final class TokenSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_tokens_sao_gravados_criptografados_no_banco(): void
    {
        $connection = CalendarConnection::factory()->create([
            'access_token' => 'ya29.super-secreto',
            'refresh_token' => '1//refresh-super-secreto',
        ]);

        // Relê ignorando o cast: o que está na coluna é o valor criptografado.
        $raw = $this->getConnectionQuery($connection->id)->first();

        $this->assertNotSame('ya29.super-secreto', $raw->access_token);
        $this->assertNotSame('1//refresh-super-secreto', $raw->refresh_token);
        $this->assertStringNotContainsString('super-secreto', $raw->access_token);
    }

    public function test_tokens_sao_decifrados_ao_ler_o_model(): void
    {
        $connection = CalendarConnection::factory()->create(['access_token' => 'ya29.super-secreto']);

        $this->assertSame('ya29.super-secreto', $connection->fresh()?->access_token);
    }

    public function test_tokens_nao_aparecem_na_serializacao_do_model(): void
    {
        $connection = CalendarConnection::factory()->create([
            'access_token' => 'ya29.super-secreto',
            'refresh_token' => '1//refresh-super-secreto',
        ]);

        $serialized = json_encode($connection->toArray(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('ya29.super-secreto', $serialized);
        $this->assertStringNotContainsString('refresh-super-secreto', $serialized);
        $this->assertArrayNotHasKey('access_token', $connection->toArray());
        $this->assertArrayNotHasKey('refresh_token', $connection->toArray());
    }

    public function test_endpoint_de_conexoes_nao_expoe_tokens(): void
    {
        $user = User::factory()->create();
        CalendarConnection::factory()->create([
            'user_id' => $user->id,
            'access_token' => 'ya29.super-secreto',
            'refresh_token' => '1//refresh-super-secreto',
        ]);

        $body = $this->actingAs($user)->getJson('/api/connections')
            ->assertOk()
            ->content();

        $this->assertStringNotContainsString('super-secreto', $body);
        $this->assertStringNotContainsString('access_token', $body);
        $this->assertStringNotContainsString('refresh_token', $body);
    }

    public function test_detalhe_tecnico_do_provider_nao_vaza_na_mensagem_da_excecao(): void
    {
        $exception = CalendarProviderException::unauthorized(
            OauthProvider::Google,
            'HTTP 401: {"error":"invalid_grant","access_token":"ya29.vazado"}',
        );

        // A mensagem é o que o renderer de exceção devolve ao cliente.
        $this->assertStringNotContainsString('ya29.vazado', $exception->getMessage());
        $this->assertStringNotContainsString('invalid_grant', $exception->getMessage());
        $this->assertSame(401, $exception->statusCode);

        // O detalhe continua disponível para o log.
        $this->assertStringContainsString('ya29.vazado', $exception->context()['detail']);
    }

    public function test_resposta_422_de_reconexao_nao_inclui_detalhe_do_provider(): void
    {
        $user = User::factory()->create();

        // Conexão expirada, sem refresh token: o refresher exige reconexão.
        CalendarConnection::factory()->create([
            'user_id' => $user->id,
            'token_expires_at' => now()->subHour(),
            'refresh_token' => null,
        ]);

        $body = $this->actingAs($user)
            ->getJson('/api/calendar/google/events?start=2026-09-28T00:00:00Z&end=2026-10-05T00:00:00Z')
            ->assertStatus(422)
            ->content();

        $this->assertStringNotContainsString('fake-access-token', $body);
        $this->assertStringNotContainsString('fake-refresh-token', $body);
    }

    public function test_o_usuario_so_enxerga_as_proprias_conexoes(): void
    {
        $dono = User::factory()->create();
        $intruso = User::factory()->create();

        CalendarConnection::factory()->create([
            'user_id' => $dono->id,
            'provider' => OauthProvider::Google,
        ]);

        $body = $this->actingAs($intruso)->getJson('/api/connections')
            ->assertOk()
            ->json('data');

        $connected = array_filter(
            $body,
            static fn (array $item): bool => data_get($item, 'attributes.connected') === true,
        );

        $this->assertSame([], $connected);
    }

    public function test_oauth_state_de_outro_usuario_e_rejeitado(): void
    {
        $dono = User::factory()->create();
        $intruso = User::factory()->create();

        // O controller exige state de 64 caracteres (sha256 hex).
        $state = str_repeat('a', 64);

        // O controller grava o state no cache com o user_id; plantamos o mesmo
        // formato para simular uma sessão de autorização iniciada por outro
        // usuário no mesmo browser.
        cache()->put('oauth:state:'.hash('sha256', $state), [
            'user_id' => $dono->id,
            'provider' => OauthProvider::Google->value,
            'with_write' => false,
        ], 600);

        $this->actingAs($intruso)
            ->getJson('/api/oauth/google/callback?state='.$state.'&code=abc')
            ->assertStatus(422)
            ->assertJsonValidationErrors('state');
    }

    public function test_oauth_state_e_consumido_uma_unica_vez(): void
    {
        $user = User::factory()->create();
        $state = str_repeat('b', 64);

        cache()->put('oauth:state:'.hash('sha256', $state), [
            'user_id' => $user->id,
            'provider' => OauthProvider::Google->value,
            'with_write' => false,
        ], 600);

        // A primeira tentativa é rejeitada na troca de token (sem config de
        // provider), mas o state já deve ter sido invalidado.
        $this->actingAs($user)
            ->getJson('/api/oauth/google/callback?state='.$state.'&code=abc')
            ->assertStatus(422);

        $this->assertNull(cache()->get('oauth:state:'.hash('sha256', $state)));
    }

    public function test_oauth_callback_rejeita_state_inexistente(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/oauth/google/callback?state='.str_repeat('c', 64).'&code=abc')
            ->assertStatus(422)
            ->assertJsonValidationErrors('state');
    }

    private function getConnectionQuery(int $id): Builder
    {
        // Query builder crua: ignora casts e hooks de model, então devolve o
        // texto exatamente como está na coluna.
        return DB::table('calendar_connections')->where('id', $id);
    }
}
