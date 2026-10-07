<?php

declare(strict_types=1);

namespace App\DTOs\Calendar;

use Carbon\CarbonImmutable;

/**
 * Intervalo consultado no calendário.
 *
 * Janela máxima limitada porque cada chamada consome cota do Google/Graph —
 * um `start` de 1970 com `end` de 2100 travaria a agregação.
 */
final readonly class CalendarRange
{
    public const MAX_DAYS = 366;

    public function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
    ) {
        if ($end->lessThanOrEqualTo($start)) {
            throw new \InvalidArgumentException('A data final do intervalo deve ser posterior à inicial.');
        }

        if ($start->diffInDays($end) > self::MAX_DAYS) {
            throw new \InvalidArgumentException('O intervalo não pode exceder '.self::MAX_DAYS.' dias.');
        }
    }

    public function days(): float
    {
        return $this->start->diffInDays($this->end);
    }

    /**
     * Constrói a partir das strings ISO vindas da query (`start_date`/`end_date`).
     *
     * Lança `InvalidArgumentException` em data ilegível, e o `GetEventsRequest`
     * transforma isso em 422 — a validação de formato continua na camada de
     * entrada, não aqui.
     */
    public static function fromIso(string $startDate, string $endDate): self
    {
        $start = CarbonImmutable::parse($startDate);
        $end = CarbonImmutable::parse($endDate);

        return new self($start, $end);
    }

    /**
     * @return array{start: string, end: string}
     */
    public function toArray(): array
    {
        return [
            'start' => $this->start->toIso8601String(),
            'end' => $this->end->toIso8601String(),
        ];
    }
}
