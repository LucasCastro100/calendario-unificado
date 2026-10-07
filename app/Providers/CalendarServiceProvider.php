<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\OauthProvider;
use App\Services\Contracts\CalendarServiceInterface;
use App\Services\Google\GoogleCalendarService;
use App\Services\Microsoft\MicrosoftCalendarService;
use Illuminate\Support\ServiceProvider;

/**
 * Resolve a implementação concreta de CalendarServiceInterface a partir do
 * enum OauthProvider.
 *
 * É o único lugar do código que conhece o mapeamento provider → adapter.
 * Nenhum service faz `if ($provider === 'google')`.
 */
final class CalendarServiceProvider extends ServiceProvider
{
    /**
     * @var array<string, class-string<CalendarServiceInterface>>
     */
    private const ADAPTERS = [
        'google' => GoogleCalendarService::class,
        'microsoft' => MicrosoftCalendarService::class,
    ];

    public function register(): void
    {
        $this->app->bind(
            CalendarServiceInterface::class,
            static function ($app, array $parameters): CalendarServiceInterface {
                $provider = $parameters['provider'] ?? null;

                if (! $provider instanceof OauthProvider) {
                    // Sem provider explícito, a aplicação não tem um alvo válido —
                    // falhar aqui é melhor que escolher um adapter por engano.
                    throw new \InvalidArgumentException(
                        'CalendarServiceInterface exige o parâmetro "provider" (OauthProvider).'
                    );
                }

                $adapter = self::ADAPTERS[$provider->value] ?? null;

                if ($adapter === null) {
                    throw new \InvalidArgumentException(
                        'Nenhum adapter registrado para o provider "'.$provider->value.'".'
                    );
                }

                return $app->make($adapter);
            },
        );
    }
}
