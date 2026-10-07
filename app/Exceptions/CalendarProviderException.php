<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\OauthProvider;
use RuntimeException;

/**
 * Falha na comunicação com um provedor externo (Google / Microsoft Graph).
 *
 * Regra central: `getMessage()` é sempre genérica e é o que chega ao cliente.
 * O detalhe técnico do terceiro (URLs com token, payload de erro, ids internos)
 * fica em `detail`, acessível só via `context()` para o log. Concatenar os dois
 * numa string só transformaria qualquer log de exceção num vazamento.
 */
class CalendarProviderException extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly OauthProvider $provider,
        public readonly int $statusCode = 0,
        public readonly ?string $detail = null,
    ) {
        parent::__construct($message);
    }

    /**
     * Erro de autenticação — a conexão precisa ser refeita pelo usuário.
     * Traduzido para HTTP 422 com código estável, nunca 500.
     */
    public static function unauthorized(OauthProvider $provider, string $detail = ''): self
    {
        return new self(
            'Credenciais inválidas ou expiradas para '.$provider->label().'. Reconecte sua conta.',
            $provider,
            401,
            $detail,
        );
    }

    public static function serverError(OauthProvider $provider, string $detail = ''): self
    {
        return new self(
            $provider->label().' está indisponível no momento. Tente novamente.',
            $provider,
            502,
            $detail,
        );
    }

    public static function rateLimited(OauthProvider $provider, string $detail = ''): self
    {
        return new self(
            'Limite de requisições no '.$provider->label().' atingido. Tente novamente em instantes.',
            $provider,
            429,
            $detail,
        );
    }

    public static function requestFailed(OauthProvider $provider, string $detail = ''): self
    {
        return new self(
            'Não foi possível sincronizar com '.$provider->label().'.',
            $provider,
            502,
            $detail,
        );
    }

    /**
     * Falha no refresh do token — chave de app rotacionada, refresh revogado etc.
     */
    public static function reauthRequired(OauthProvider $provider, string $detail = ''): self
    {
        return new self(
            'É preciso reconectar o '.$provider->label().' para continuar.',
            $provider,
            422,
            $detail,
        );
    }

    /**
     * Contexto para log. Só o servidor enxerga isto.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return array_filter([
            'provider' => $this->provider->value,
            'status' => $this->statusCode,
            'detail' => $this->detail,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
