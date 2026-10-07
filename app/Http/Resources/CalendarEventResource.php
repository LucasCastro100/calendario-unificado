<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\DTOs\Calendar\UnifiedEventDTO;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Envolve o DTO na envelope `data` da API. Não expõe o model — a serialização
 * vem de `UnifiedEventDTO::toArray()`, que é a única forma de contrato.
 *
 * O DTO já carrega `id` e `provider`, então não há um envelope JSON:API
 * embrulhando tudo em `attributes`. Aninhar só duplicaria o `id` e obrigaria o
 * cliente a saber que existe um envelope.
 *
 * @property-read UnifiedEventDTO $resource
 */
final class CalendarEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}
