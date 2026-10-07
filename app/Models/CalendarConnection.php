<?php

namespace App\Models;

use App\Enums\OauthProvider;
use Database\Factories\CalendarConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Credenciais OAuth de um usuário em um provedor.
 *
 * REGRA INEGOCIÁVEL: `access_token` e `refresh_token` têm cast `encrypted`.
 * O `#[Hidden]` é a segunda barreira — mesmo que o cast falhe, a serialização
 * padrão do model não expõe as credenciais.
 */
#[Fillable([
    'user_id',
    'provider',
    'provider_account_id',
    'account_email',
    'scopes',
    'access_token',
    'refresh_token',
    'token_expires_at',
    'calendar_ids',
    'scopes_revoked_at',
    'last_synced_at',
])]
#[Hidden(['access_token', 'refresh_token'])]
class CalendarConnection extends Model
{
    /** @use HasFactory<CalendarConnectionFactory> */
    use HasFactory;

    /**
     * Margem de segurança para renovar o token antes de realmente expirar:
     * evita que uma requisição em trânsito receba 401 por expiração iminente.
     */
    public const REFRESH_MARGIN_SECONDS = 60;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => OauthProvider::class,
            'scopes' => 'array',
            'calendar_ids' => 'array',
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'scopes_revoked_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<CalendarEventCache, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(CalendarEventCache::class);
    }

    /**
     * A conexão pode ser usada para chamar o provider agora?
     */
    public function isUsable(): bool
    {
        return $this->access_token !== null
            && $this->scopes_revoked_at === null;
    }

    /**
     * O token precisa ser renovado antes da próxima requisição?
     */
    public function needsRefresh(): bool
    {
        if ($this->refresh_token === null) {
            return false;
        }

        if ($this->token_expires_at === null) {
            return true;
        }

        return $this->token_expires_at
            ->subSeconds(self::REFRESH_MARGIN_SECONDS)
            ->isPast();
    }

    /**
     * Renovar token é possível sem novo consentimento do usuário?
     */
    public function canRefresh(): bool
    {
        return $this->isUsable() && $this->refresh_token !== null;
    }

    /**
     * A conexão tem permissão de escrita no provider?
     */
    public function canWrite(): bool
    {
        return in_array($this->provider->writeScope(), $this->scopes ?? [], true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    #[Scope]
    protected function usable(Builder $query): Builder
    {
        return $query
            ->whereNull('scopes_revoked_at')
            ->whereNotNull('access_token');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    #[Scope]
    protected function forProvider(Builder $query, OauthProvider $provider): Builder
    {
        return $query->where('provider', $provider->value);
    }
}
