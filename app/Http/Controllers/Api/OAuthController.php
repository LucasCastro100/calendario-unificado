<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\OauthProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\OAuthCallbackRequest;
use App\Http\Requests\Api\OAuthProviderRequest;
use App\Models\CalendarConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Fluxo OAuth dos provedores de calendário.
 *
 * O redirect gera um `state` opaco guardado no cache e atrelado ao usuário. O
 * callback valida esse state antes de qualquer troca de token — sem isso, um
 * atacante inicia o fluxo com a conta dele e o victim's browser grava a
 * conexão (login CSRF clássico).
 */
final class OAuthController extends Controller
{
    /**
     * O state vive no cache pelo tempo de vida do redirect. Suficiente para o
     * usuário conclure o consent na tela do provedor.
     */
    private const STATE_TTL_SECONDS = 600;

    /**
     * Redireciona para a tela de consent do provedor.
     */
    public function redirect(OAuthProviderRequest $request): RedirectResponse
    {
        $provider = $request->provider();
        $withWrite = $request->boolean('write', false);

        $state = Str::random(64);

        Cache::put(
            $this->stateKey($state),
            [
                'user_id' => $request->user()->id,
                'provider' => $provider->value,
                'with_write' => $withWrite,
            ],
            self::STATE_TTL_SECONDS,
        );

        $config = $this->clientConfig($provider);

        $query = http_build_query([
            'client_id' => $config['client_id'],
            'redirect_uri' => $this->redirectUri($provider),
            'response_type' => 'code',
            // add_access_type=offline + prompt=consent só existem no Google;
            // no Microsoft o offline_access já garante o refresh token.
            ...($provider === OauthProvider::Google
                ? ['access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'true']
                : []),
            'scope' => implode(' ', $provider->scopes($withWrite)),
            'state' => $state,
        ]);

        return redirect()->away($config['authorize_url'].'?'.$query);
    }

    /**
     * Recebe o code, troca por tokens e persiste criptografado.
     */
    public function callback(OAuthCallbackRequest $request): JsonResponse
    {
        $state = $request->validated('state');

        $cached = Cache::get($this->stateKey($state));

        if ($cached === null) {
            throw ValidationException::withMessages([
                'state' => ['Sessão de autorização inválida ou expirada. Tente conectar novamente.'],
            ]);
        }

        // Single-use: consumir o state invalida qualquer replay.
        Cache::forget($this->stateKey($state));

        // O state é atrelado ao usuário que iniciou — se não for o mesmo da
        // sessão atual, o fluxo foi forjado em outra aba/usuário.
        if ((int) $cached['user_id'] !== (int) $request->user()->id) {
            throw ValidationException::withMessages([
                'state' => ['Sessão de autorização não corresponde ao usuário atual.'],
            ]);
        }

        // Consentimento negado: o state é válido, então é um resultado legítimo
        // — não um ataque. Responder 422 com mensagem clara, sem chegar à troca.
        if ($request->wasDenied()) {
            throw ValidationException::withMessages([
                'error' => ['Você recusou o acesso ao calendário. Nenhuma conta foi conectada.'],
            ]);
        }

        $code = $request->validated('code');
        $config = $this->clientConfig($provider = OauthProvider::from($cached['provider']));

        try {
            $response = Http::asForm()
                ->timeout(15)
                ->post($config['token_url'], [
                    'client_id' => $config['client_id'],
                    'client_secret' => $config['client_secret'],
                    'redirect_uri' => $this->redirectUri($provider),
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                ]);
        } catch (Throwable $exception) {
            Log::error('OAuth token exchange failed', [
                'provider' => $provider->value,
                'error' => $exception->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'code' => ['Não foi possível concluir a conexão com o provedor.'],
            ]);
        }

        if (! $response->successful()) {
            // O corpo pode conter o code usado; nunca logar o payload cru.
            Log::error('OAuth token exchange rejected', [
                'provider' => $provider->value,
                'status' => $response->status(),
            ]);

            throw ValidationException::withMessages([
                'code' => ['O provedor recusou a autorização. Tente novamente.'],
            ]);
        }

        $accessToken = $response->json('access_token');

        if (! is_string($accessToken) || $accessToken === '') {
            throw ValidationException::withMessages([
                'code' => ['O provedor não retornou um token de acesso.'],
            ]);
        }

        $refreshToken = $response->json('refresh_token');
        $expiresIn = (int) ($response->json('expires_in') ?? 3600);

        $scopes = $cached['with_write']
            ? $provider->scopes(true)
            : $provider->scopes(false);

        // `updateOrCreate` em vez de `create`: reconectar uma conta já
        // conectada deve atualizar, não violar o unique (user_id, provider).
        // Quando o provider não devolve refresh token — o Google só o emite no
        // primeiro consentimento — o `fill` abaixo gravaria null e apagaria o
        // token anterior, quebrando a renovação futura. Por isso montamos os
        // atributos condicionalmente.
        $attributes = [
            'provider_account_id' => $this->accountId($provider, $accessToken),
            'account_email' => $request->user()->email,
            'scopes' => $scopes,
            // Cast `encrypted` do model cuida da criptografia. Em nenhum
            // momento o token toca o log ou a resposta.
            'access_token' => $accessToken,
            'token_expires_at' => now()->addSeconds($expiresIn),
            'calendar_ids' => ['primary'],
            // Reconectar limpa a marca de revogação.
            'scopes_revoked_at' => null,
        ];

        if (is_string($refreshToken) && $refreshToken !== '') {
            $attributes['refresh_token'] = $refreshToken;
        }

        $connection = CalendarConnection::updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'provider' => $provider->value,
            ],
            $attributes,
        );

        return response()->json([
            'data' => [
                'provider' => $provider->value,
                'connected' => true,
                'account_email' => $connection->account_email,
                'can_write' => $connection->canWrite(),
            ],
        ]);
    }

    /**
     * Desconecta e apaga as credenciais.
     */
    public function disconnect(OAuthProviderRequest $request): JsonResponse
    {
        $provider = $request->provider();

        /** @var CalendarConnection $connection */
        $connection = CalendarConnection::query()
            ->where('user_id', $request->user()->id)
            ->where('provider', $provider->value)
            ->firstOrFail();

        // Policy: escrita exige posse estrita. Autoriza antes de apagar.
        $this->authorize('delete', $connection);

        $connection->delete();

        return response()->json(status: 204);
    }

    private function stateKey(string $state): string
    {
        return 'oauth:state:'.hash('sha256', $state);
    }

    /**
     * Identidade da conta conectada no provider, para não confundir contas
     * diferentes do mesmo usuário em sessions distintas.
     */
    private function accountId(OauthProvider $provider, string $accessToken): ?string
    {
        try {
            $url = $provider === OauthProvider::Google
                ? 'https://www.googleapis.com/oauth2/v3/userinfo'
                : 'https://graph.microsoft.com/v1.0/me?$select=id';

            $response = Http::withToken($accessToken)->acceptJson()->timeout(10)->get($url);

            if (! $response->successful()) {
                return null;
            }

            $id = $provider === OauthProvider::Google
                ? $response->json('sub')
                : $response->json('id');

            return is_string($id) ? $id : null;
        } catch (Throwable) {
            // Falha ao identificar a conta não impede a conexão.
            return null;
        }
    }

    /**
     * @return array{authorize_url: string, token_url: string, client_id: string, client_secret: string}
     */
    private function clientConfig(OauthProvider $provider): array
    {
        return match ($provider) {
            OauthProvider::Google => [
                'authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
                'token_url' => 'https://oauth2.googleapis.com/token',
                'client_id' => (string) config('services.google.client_id'),
                'client_secret' => (string) config('services.google.client_secret'),
            ],
            OauthProvider::Microsoft => [
                'authorize_url' => 'https://login.microsoftonline.com/'.config('services.microsoft.tenant', 'common').'/oauth2/v2.0/authorize',
                'token_url' => 'https://login.microsoftonline.com/'.config('services.microsoft.tenant', 'common').'/oauth2/v2.0/token',
                'client_id' => (string) config('services.microsoft.client_id'),
                'client_secret' => (string) config('services.microsoft.client_secret'),
            ],
        };
    }

    private function redirectUri(OauthProvider $provider): string
    {
        return rtrim((string) config('app.frontend_url'), '/')
            .'/api/oauth/'.$provider->value.'/callback';
    }
}
