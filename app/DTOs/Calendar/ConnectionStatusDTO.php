<?php

declare(strict_types=1);

namespace App\DTOs\Calendar;

use App\Enums\OauthProvider;
use Carbon\CarbonImmutable;

/**
 * Estado de uma conexão com um provedor, exposto ao cliente.
 *
 * NÃO inclui access_token nem refresh_token em nenhum campo — a serialização é
 * manual justamente para garantir que credenciais não escapem por engano quando
 * um campo novo for adicionado ao model.
 */
final readonly class ConnectionStatusDTO
{
    /**
     * @param  array<int, string>  $scopes
     */
    public function __construct(
        public OauthProvider $provider,
        public bool $connected,
        public ?string $accountEmail = null,
        public array $scopes = [],
        public ?CarbonImmutable $tokenExpiresAt = null,
        public ?CarbonImmutable $lastSyncedAt = null,
        public bool $canWrite = false,
    ) {}

    /**
     * @return array{
     *     provider: string,
     *     label: string,
     *     connected: bool,
     *     account_email: ?string,
     *     scopes: array<int, string>,
     *     can_write: bool,
     *     token_expires_at: ?string,
     *     last_synced_at: ?string
     * }
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider->value,
            'label' => $this->provider->label(),
            'connected' => $this->connected,
            'account_email' => $this->accountEmail,
            'scopes' => $this->scopes,
            'can_write' => $this->canWrite,
            'token_expires_at' => $this->tokenExpiresAt?->toIso8601String(),
            'last_synced_at' => $this->lastSyncedAt?->toIso8601String(),
        ];
    }
}
