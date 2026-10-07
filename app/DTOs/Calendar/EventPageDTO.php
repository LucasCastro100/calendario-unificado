<?php

declare(strict_types=1);

namespace App\DTOs\Calendar;

use Illuminate\Support\Collection;

/**
 * Uma página de eventos vinda de um provider, já normalizada em DTO.
 *
 * Existe para separar as duas metades de uma leitura paginada:
 * a **construção da request** (que o agregador coloca dentro do Http::pool e
 * executa em paralelo) e a **normalização** (que só pode rodar depois que a
 * resposta chegou).
 *
 * O Google pagina com `pageToken` (reaproveita a mesma URL base); o Graph
 * devolve um `@odata.nextLink` absoluto. `nextRequest()` absorve a diferença.
 */
final readonly class EventPageDTO
{
    /**
     * @param  Collection<int, UnifiedEventDTO>  $events
     * @param  array<string, mixed>  $nextRequest  null quando não há próxima página.
     */
    public function __construct(
        public Collection $events,
        public ?array $nextRequest = null,
    ) {}

    public function hasNextPage(): bool
    {
        return $this->nextRequest !== null;
    }
}
