<?php

namespace App\Models;

use App\DTOs\Calendar\ConnectionStatusDTO;
use App\Enums\OauthProvider;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * @return HasMany<CalendarConnection, $this>
     */
    public function calendarConnections(): HasMany
    {
        return $this->hasMany(CalendarConnection::class);
    }

    /**
     * @return HasMany<CalendarEventCache, $this>
     */
    public function calendarEventCaches(): HasMany
    {
        return $this->hasMany(CalendarEventCache::class);
    }

    /**
     * Conexões que podem ser usadas para ler eventos agora.
     *
     * @return HasMany<CalendarConnection, $this>
     */
    public function activeCalendarConnections(): HasMany
    {
        return $this->calendarConnections()
            ->whereNull('scopes_revoked_at')
            ->whereNotNull('access_token');
    }

    /**
     * Estado de cada provider para a tela de conexões — um item por provider,
     * conectado ou não.
     *
     * @return array<int, ConnectionStatusDTO>
     */
    public function calendarConnectionDtos(): array
    {
        $connections = $this->calendarConnections->keyBy(
            static fn (CalendarConnection $connection): string => $connection->provider->value,
        );

        return array_map(
            static function (OauthProvider $provider) use ($connections): ConnectionStatusDTO {
                $connection = $connections->get($provider->value);

                if ($connection === null) {
                    return new ConnectionStatusDTO(provider: $provider, connected: false);
                }

                $scopes = $connection->scopes ?? [];

                // O cast `datetime` devolve Carbon mutável; o DTO é readonly e
                // exige imutável. Convertemos na fronteira para que nenhuma
                // referência externa consiga alterar a data do value object.
                return new ConnectionStatusDTO(
                    provider: $provider,
                    connected: $connection->isUsable(),
                    accountEmail: $connection->account_email,
                    scopes: $scopes,
                    tokenExpiresAt: $connection->token_expires_at?->toImmutable(),
                    lastSyncedAt: $connection->last_synced_at?->toImmutable(),
                    canWrite: in_array($provider->writeScope(), $scopes, true),
                );
            },
            OauthProvider::cases(),
        );
    }
}
