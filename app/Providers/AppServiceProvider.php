<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\CalendarConnection;
use App\Policies\CalendarConnectionPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Registro de dependências de container.
     */
    public function register(): void
    {
        //
    }

    /**
     * Inicialização da aplicação.
     */
    public function boot(): void
    {
        // Policy anti-IDOR registrada explicitamente: não depender da
        // convenção de descoberta, porque é a barreira de segurança que
        // impede um usuário de acessar a conexão de outro.
        Gate::policy(CalendarConnection::class, CalendarConnectionPolicy::class);

        // Piso de senha maior que o padrão do Laravel (8). Credencial que dá
        // acesso ao calendário de uma pessoa merece mais.
        Password::defaults(fn (): Password => Password::min(10));

        $this->registerRateLimiters();
    }

    /**
     * Rate limiters nomeados, consumidos via `throttle:nome` nas rotas.
     *
     * A chave é o usuário autenticado quando existe, e o IP quando anônimo.
     * Limitar por IP em rede corporativa (NAT) cortaria vários usuários de uma
     * vez, então a sessão tem precedência sobre o endereço.
     */
    private function registerRateLimiters(): void
    {
        // Login/registro: anônimo por definição, então cai no IP.
        RateLimiter::for('auth', function (Request $request): Limit {
            return Limit::perMinute(10)->by($this->rateLimitKey($request));
        });

        // Leitura de calendário: cada chamada consome cota do Google/Graph.
        RateLimiter::for('calendar-read', function (Request $request): Limit {
            return Limit::perMinute(120)->by($this->rateLimitKey($request));
        });

        // Escrita: bem mais baixa — cada evento criado é uma escrita real no
        // calendário do usuário.
        RateLimiter::for('calendar-write', function (Request $request): Limit {
            return Limit::perMinute(30)->by($this->rateLimitKey($request));
        });

        // OAuth é o alvo mais atraente para abuso: leva a bloqueio de conta no
        // provider e suspensão do app.
        RateLimiter::for('oauth', function (Request $request): Limit {
            return Limit::perMinute(20)->by($this->rateLimitKey($request));
        });
    }

    private function rateLimitKey(Request $request): string
    {
        $user = $request->user();

        return $user !== null
            ? 'user:'.$user->getAuthIdentifier()
            : 'ip:'.$request->ip();
    }
}
