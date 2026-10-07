<?php

namespace App\Models;

use App\Enums\OauthProvider;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cópia local do evento remoto, usada como fonte de verdade quando o provider
 * está indisponível. Nunca é exposto diretamente — a API devolve o DTO.
 */
#[Fillable([
    'user_id',
    'connection_id',
    'provider',
    'remote_id',
    'uid',
    'payload',
    'starts_at',
    'ends_at',
    'fetched_at',
])]
class CalendarEventCache extends Model
{
    /**
     * Tempo que o cache é considerado válido antes de uma nova busca ao provider.
     */
    public const TTL_MINUTES = 15;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => OauthProvider::class,
            'payload' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'fetched_at' => 'datetime',
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
     * @return BelongsTo<CalendarConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(CalendarConnection::class);
    }

    public function isStale(): bool
    {
        return $this->fetched_at === null
            || $this->fetched_at->addMinutes(self::TTL_MINUTES)->isPast();
    }

    /**
     * Eventos que sobrepõem o intervalo, com paginação por cursor.
     *
     * A comparação é feita com `<`/`>` em vez de `whereBetween` porque um evento
     * que termina exatamente às 00:00 do dia seguinte ainda pertence ao range
     * visualizado.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    #[Scope]
    protected function overlapping(Builder $query, CarbonInterface $start, CarbonInterface $end): Builder
    {
        return $query
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start);
    }
}
