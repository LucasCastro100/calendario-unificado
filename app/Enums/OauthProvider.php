<?php

declare(strict_types=1);

namespace App\Enums;

use Illuminate\Validation\ValidationException;

/**
 * Provedores de calendário suportados.
 *
 * O binding da implementação concreta é feito em CalendarServiceProvider a partir
 * deste enum — nunca com `if` de provider espalhado pelo código.
 */
enum OauthProvider: string
{
    case Google = 'google';
    case Microsoft = 'microsoft';

    /**
     * Rótulo exibido na interface. O backend envia o valor, não a apresentação,
     * mas o fallback em pt-BR evita payload vazio para o usuário.
     */
    public function label(): string
    {
        return match ($this) {
            self::Google => 'Google Calendar',
            self::Microsoft => 'Microsoft 365',
        };
    }

    /**
     * Cor de marca do provider, usada como fallback do evento na agenda.
     *
     * Vai no payload para que qualquer cliente (web, mobile, e-mail) renderize
     * a mesma cor sem duplicar a tabela. O frontend pode sobrescrever para
     * adaptar ao próprio tema — daí o FullCalendar manter seu próprio mapa.
     */
    public function color(): string
    {
        return match ($this) {
            self::Google => '#4285F4',
            self::Microsoft => '#6264A7',
        };
    }

    /**
     * Escopo OAuth somente-leitura. Usado quando o usuário só autoriza leitura.
     */
    public function readScope(): string
    {
        return match ($this) {
            self::Google => 'https://www.googleapis.com/auth/calendar.readonly',
            self::Microsoft => 'Calendars.Read',
        };
    }

    /**
     * Escopo OAuth com escrita. Scope de escrita sempre inclui o de leitura.
     */
    public function writeScope(): string
    {
        return match ($this) {
            self::Google => 'https://www.googleapis.com/auth/calendar.events',
            self::Microsoft => 'Calendars.ReadWrite',
        };
    }

    /**
     * Escopo offline — obrigatório para renovar o token sem novo consentimento.
     */
    public function offlineScope(): string
    {
        return match ($this) {
            self::Google => 'https://www.googleapis.com/auth/calendar.readonly',
            self::Microsoft => 'offline_access',
        };
    }

    /**
     * Valores aceitos na entrada (path param, query string). Usado em Rules.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<int, string>
     */
    public function scopes(bool $withWrite): array
    {
        $scopes = [$this->readScope()];

        if ($withWrite) {
            $scopes[] = $this->writeScope();
        }

        $offline = $this->offlineScope();

        if (! in_array($offline, $scopes, true)) {
            $scopes[] = $offline;
        }

        return array_values(array_unique($scopes));
    }

    /**
     * Constrói o enum a partir de entrada do usuário (path param, query string),
     * launching ValidationException para valor desconhecido em vez de TypeError.
     *
     * @throws ValidationException
     */
    public static function fromInput(?string $value): self
    {
        return self::tryFrom((string) $value) ?? throw ValidationException::withMessages([
            'provider' => ['Provedor de calendário inválido.'],
        ]);
    }
}
