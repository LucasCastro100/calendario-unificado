<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Requests\Api\RegisterRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Autenticação por sessão do Laravel, no modo stateful do Sanctum.
 *
 * O controller só valida, verifica credenciais e abre/encerra a sessão —
 * nenhuma regra de calendário aqui.
 *
 * **Por que sessão e não token Bearer:** o browser não fala com esta API. O
 * Next.js faz o proxy e repassa o cookie HTTP-Only. Se o login devolvesse um
 * token, ele acabaria em algum armazenamento acessível ao JavaScript
 * (localStorage), que é exatamente o que o XSS precisa para exfiltrar a
 * identidade do usuário. O cookie HTTP-Only não é legível por script.
 *
 * A proteção contra CSRF não é descartada: as requisições que chegam aqui
 * passam por `EnsureFrontendRequestsAreStateful`, que liga `StartSession` e
 * `PreventRequestForgery`. Ver security-rules.md seção 6.
 */
final class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'password' => $request->validated('password'),
        ]);

        $this->startSession($request, $user);

        return response()->json([
            'data' => ['user' => $this->userPayload($user)],
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();

        // Mensagem única para e-mail inexistente e senha errada: distinguir os
        // dois permite enumerar contas cadastradas.
        if ($user === null || ! Hash::check($request->validated('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Credenciais inválidas.'],
            ]);
        }

        $this->startSession($request, $user);

        return response()->json([
            'data' => ['user' => $this->userPayload($user)],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        // A sessão só existe em requisição stateful (ver `startSession`). Sem
        // este guarda, um logout vía token Bearer — que o `auth:sanctum` também
        // aceita — estouraria "Session store not set" e devolveria 500.
        if ($request->hasSession()) {
            // Invalidate regenera o ID e apaga os dados da sessão antiga; sem
            // isso o cookie anterior continuaria válido caso fosse interceptado.
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(status: 204);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => ['user' => $this->userPayload($user)],
        ]);
    }

    /**
     * Autentica no guard `web` e regenera o ID de sessão.
     *
     * O regenerate é obrigatório: sem ele, um ID fixado antes do login seria
     * reutilizado depois, abrindo para fixação de sessão.
     */
    private function startSession(Request $request, User $user): void
    {
        Auth::guard('web')->login($user);

        $request->session()->regenerate();
    }

    /**
     * @return array{id: int, name: string, email: string}
     */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ];
    }
}
