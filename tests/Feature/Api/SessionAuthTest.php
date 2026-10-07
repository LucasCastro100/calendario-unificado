<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Tests\TestCase;

/**
 * Autenticação por sessão (Sanctum stateful).
 *
 * A aplicação inteira depende de uma decisão: o login abre **sessão**, não
 * devolve token. O browser nunca fala com a API — o Next faz o proxy e repassa
 * o cookie HTTP-Only — então um token guardado em `localStorage` seria
 * exatamente o que o XSS precisa para exfiltrar a identidade do usuário.
 *
 * Os testes abaixo exercitam o caminho real (registro → cookie → requisição
 * seguinte), e não `actingAs()`, porque `actingAs` injeta o usuário direto no
 * guard e esconde justamente o que costuma quebrar: o repasse do cookie de
 * sessão entre o Next e o Laravel.
 */
final class SessionAuthTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'senha-super-segura';

    /**
     * O proxy do Next se identifica com este `Referer`.
     *
     * `EnsureFrontendRequestsAreStateful::fromFrontend()` compara `Referer`
     * (ou `Origin`) com `SANCTUM_STATEFUL_DOMAINS`. Sem ele, o Laravel não liga
     * `StartSession`, ignora o cookie de sessão e responde 401 em tudo — mesmo
     * com o login feito. É a dependência que o `apiFetch` do Next satisfaz.
     */
    private const FRONTEND_REFERER = 'http://localhost:3000';

    public function test_registro_cria_a_conta_e_abre_sessao(): void
    {
        $response = $this->withHeaders(['Referer' => self::FRONTEND_REFERER])
            ->postJson('/api/auth/register', self::registrationPayload());

        $response->assertCreated()
            ->assertJsonPath('data.user.email', 'ana@example.com')
            // A sessão precisa nascer com o cookie: é ele que autentica as
            // requisições seguintes.
            ->assertCookie(config('session.cookie'));

        $this->assertDatabaseHas('users', ['email' => 'ana@example.com']);
    }

    public function test_login_devolve_o_usuario_sem_nenhum_token(): void
    {
        $user = $this->createUser();

        $response = $this->withHeaders(['Referer' => self::FRONTEND_REFERER])
            ->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => self::PASSWORD,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertCookie(config('session.cookie'));

        // Nenhum campo de credencial no corpo: nem token, nem session ID.
        // Se alguém reintroduzir o token aqui, este teste quebra.
        $this->assertStringNotContainsString('token', $response->content());
    }

    public function test_login_invalido_nao_autentica(): void
    {
        $user = $this->createUser();

        $response = $this->withHeaders(['Referer' => self::FRONTEND_REFERER])
            ->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'senha-errada',
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors('email');

        // O pipeline stateful abre sessão mesmo numa tentativa recusada, então o
        // cookie existe — o que não pode existir é uma sessão autenticada. É o
        // que o proxy do Next repassa ao browser.
        $sessionId = sessionIdFromResponse($response);

        $this->assertNotNull($sessionId);

        $this->withCookie(config('session.cookie'), $sessionId)
            ->withHeaders(['Referer' => self::FRONTEND_REFERER])
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    /**
     * E-mail inexistente e senha errada produzem a mesma resposta.
     *
     * Distinguir as duas permitiria enumerar as contas cadastradas.
     */
    public function test_login_nao_distingue_email_inexistente_de_senha_errada(): void
    {
        $user = $this->createUser();

        $inexistente = $this->withHeaders(['Referer' => self::FRONTEND_REFERER])
            ->postJson('/api/auth/login', [
                'email' => 'ninguem@example.com',
                'password' => self::PASSWORD,
            ]);

        $senhaErrada = $this->withHeaders(['Referer' => self::FRONTEND_REFERER])
            ->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'senha-errada',
            ]);

        $this->assertSame($inexistente->json(), $senhaErrada->json());
    }

    public function test_login_passa_a_autenticar_a_requisicao_seguinte(): void
    {
        $user = $this->createUser();

        $sessionId = sessionIdFromResponse(
            $this->withHeaders(['Referer' => self::FRONTEND_REFERER])
                ->postJson('/api/auth/login', [
                    'email' => $user->email,
                    'password' => self::PASSWORD,
                ])
                ->assertOk(),
        );

        $this->assertNotNull($sessionId);

        // O fluxo exato do browser: a requisição seguinte carrega o cookie.
        $this->withCookie(config('session.cookie'), $sessionId)
            ->withHeaders(['Referer' => self::FRONTEND_REFERER])
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.email', $user->email);
    }

    /**
     * O `Referer` do proxy é o que liga o `StartSession`.
     *
     * `EnsureFrontendRequestsAreStateful::fromFrontend()` compara `Referer`
     * (ou `Origin`) com `SANCTUM_STATEFUL_DOMAINS`. Só quando a resposta é
     * positiva o middleware encadeia `StartSession` e o cookie de sessão passa a
     * valer. Como o fetch para a API sai do servidor Next — não do browser — o
     * header não vem sozinho: o `apiFetch` precisa enviá-lo.
     *
     * Este é o teste que fixa essa dependência. Sem ele, remover o header do
     * `apiFetch` produz um sintoma confuso em produção ("login responde 200 e a
     * chamada seguinte dá 401") em vez de uma falha aqui.
     */
    public function test_somente_o_referer_do_front_end_torna_a_requisicao_stateful(): void
    {
        // A lista real vem do `.env` em dev; aqui é fixada para o teste não
        // depender de configuração de máquina.
        config(['sanctum.stateful' => ['localhost:3000']]);

        $this->assertTrue(
            EnsureFrontendRequestsAreStateful::fromFrontend(
                $this->statefulRequest('http://localhost:3000/login'),
            ),
            'Uma requisição do frontend precisa ser reconhecida como stateful.',
        );

        $this->assertFalse(
            EnsureFrontendRequestsAreStateful::fromFrontend(
                $this->statefulRequest(null),
            ),
            'Sem Referer nem Origin a sessão não é iniciada e o cookie é ignorado.',
        );

        $this->assertFalse(
            EnsureFrontendRequestsAreStateful::fromFrontend(
                $this->statefulRequest('http://site-malicioso.example/login'),
            ),
            'Origem fora da lista deve continuar sendo rejeitada.',
        );
    }

    private function statefulRequest(?string $referer): Request
    {
        $server = $referer === null ? [] : ['HTTP_REFERER' => $referer];

        return Request::create('/api/auth/me', 'GET', server: $server);
    }

    /**
     * O ID de sessão muda a cada login (prevenção de fixação de sessão).
     *
     * Um ID conhecido antes do login não pode continuar valendo depois dele.
     */
    public function test_o_id_de_sessao_e_regenerado_no_login(): void
    {
        $user = $this->createUser();

        $primeiro = sessionIdFromResponse(
            $this->withHeaders(['Referer' => self::FRONTEND_REFERER])
                ->postJson('/api/auth/login', [
                    'email' => $user->email,
                    'password' => self::PASSWORD,
                ])
                ->assertOk(),
        );

        $this->assertNotNull($primeiro);

        // Reenvia o cookie da sessão anterior: o Laravel carrega essa sessão,
        // autentica e regenera. O cookie novo precisa ser diferente do antigo.
        $segundo = sessionIdFromResponse(
            $this->withCookie(config('session.cookie'), $primeiro)
                ->withHeaders(['Referer' => self::FRONTEND_REFERER])
                ->postJson('/api/auth/login', [
                    'email' => $user->email,
                    'password' => self::PASSWORD,
                ])
                ->assertOk(),
        );

        $this->assertNotNull($segundo);
        $this->assertNotSame(
            $primeiro,
            $segundo,
            'O ID de sessão precisa ser regenerado no login (fixação de sessão).',
        );
    }

    public function test_logout_encerra_a_sessao(): void
    {
        $user = $this->createUser();

        $sessionId = sessionIdFromResponse(
            $this->withHeaders(['Referer' => self::FRONTEND_REFERER])
                ->postJson('/api/auth/login', [
                    'email' => $user->email,
                    'password' => self::PASSWORD,
                ])
                ->assertOk(),
        );

        $this->assertNotNull($sessionId);

        $logout = $this->withCookie(config('session.cookie'), $sessionId)
            ->withHeaders(['Referer' => self::FRONTEND_REFERER])
            ->postJson('/api/auth/logout')
            ->assertNoContent();

        // O cookie precisa ser **expirado** na resposta, não apenas ignorado:
        // é o `Set-Cookie` que o Route Handler repassa que desloga o browser.
        // O cookie volta na resposta porque o `Session` foi invalidado e
        // regenerado; é esse `Set-Cookie` que o Route Handler repassa.
        $this->assertNotNull(
            $logout->getCookie(config('session.cookie')),
            'O logout precisa emitir um cookie de sessão novo.',
        );

        // O ponto que importa: o cookie antigo não autentica mais nada. Se o
        // logout devolvesse 204 sem invalidar a sessão, o usuário continuaria
        // logado e o botão "Sair" seria decorativo.
        $this->withCookie(config('session.cookie'), $sessionId)
            ->withHeaders(['Referer' => self::FRONTEND_REFERER])
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    public function test_logout_exige_sessao(): void
    {
        $this->postJson('/api/auth/logout')->assertUnauthorized();
    }

    /**
     * Usuário criado com a senha conhecida, para que o login possa ser exercitado
     * de verdade (a factory usa `password`).
     */
    private function createUser(): User
    {
        return User::factory()->create([
            'email' => 'ana@example.com',
            'password' => self::PASSWORD,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private static function registrationPayload(): array
    {
        return [
            'name' => 'Ana Souza',
            'email' => 'ana@example.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ];
    }
}

/**
 * Valor (já descriptografado) do cookie de sessão emitido na resposta.
 *
 * `getCookie()` descriptografa porque `EncryptCookies` faz parte do pipeline
 * stateful — o valor bruto não é o ID de sessão. Reenviar o valor descriptografado
 * com `withCookie()` funciona porque o helper de teste o criptografa de novo,
 * reproduzindo o ciclo real browser → Laravel.
 */
function sessionIdFromResponse(TestResponse $response): ?string
{
    return $response->getCookie(config('session.cookie'))?->getValue();
}
