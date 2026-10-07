<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OauthProvider;
use App\Models\CalendarConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CalendarConnection>
 */
class CalendarConnectionFactory extends Factory
{
    protected $model = CalendarConnection::class;

    /**
     * Tokens de mentira, não credenciais reais — os casts `encrypted` do model
     * ainda são exercidos, o que o torna útil para garantir que a criptografia
     * não vaza em `toArray()`/serialização.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => OauthProvider::Google,
            'provider_account_id' => (string) Str::uuid(),
            'account_email' => $this->faker->unique()->safeEmail(),
            'scopes' => OauthProvider::Google->scopes(false),
            'access_token' => 'fake-access-token',
            'refresh_token' => 'fake-refresh-token',
            'token_expires_at' => now()->addHour(),
            'calendar_ids' => ['primary'],
            'scopes_revoked_at' => null,
            'last_synced_at' => null,
        ];
    }

    /**
     * Conexão com token expirado, para exercitar a renovação.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'token_expires_at' => now()->subMinute(),
        ]);
    }

    /**
     * Conexão já desativada por revogação de escopo.
     */
    public function revoked(): static
    {
        return $this->state(fn (array $attributes): array => [
            'scopes_revoked_at' => now(),
        ]);
    }

    public function microsoft(): static
    {
        return $this->state(fn (array $attributes): array => [
            'provider' => OauthProvider::Microsoft,
            'scopes' => OauthProvider::Microsoft->scopes(false),
        ]);
    }
}
