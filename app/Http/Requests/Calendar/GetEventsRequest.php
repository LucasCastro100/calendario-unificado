<?php

declare(strict_types=1);

namespace App\Http\Requests\Calendar;

use App\DTOs\Calendar\CalendarRange;
use App\Enums\OauthProvider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validação da leitura de eventos. Vale tanto para a rota agregada
 * (`GET /api/calendar/events`) quanto para a rota por provider.
 *
 * O contrato público usa `start_date`/`end_date` em ISO 8601. O nome é
 * explícito porque o parâmetro já vai virar `start_time`/`end_time` no evento
 * devolvido — `start`/`end` sozinho seria ambíguo entre query e evento.
 */
final class GetEventsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'start_date' => ['required', 'string', 'date'],
            'end_date' => ['required', 'string', 'date', 'after:start_date'],
            'providers' => ['sometimes', 'array', 'max:5'],
            'providers.*' => ['string', Rule::in(OauthProvider::values())],
            'search' => ['sometimes', 'string', 'max:200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'start_date.required' => 'Informe a data inicial do período.',
            'start_date.date' => 'A data inicial deve estar em formato ISO 8601.',
            'end_date.required' => 'Informe a data final do período.',
            'end_date.date' => 'A data final deve estar em formato ISO 8601.',
            'end_date.after' => 'A data final deve ser posterior à inicial.',
            'providers.*.in' => 'Provedor de calendário inválido.',
            'search.max' => 'A busca deve ter no máximo 200 caracteres.',
        ];
    }

    /**
     * Valida a janela, não só o formato: cada chamada consome cota do
     * Google/Graph, e um intervalo de anos travaria a agregação.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                try {
                    $this->toRange();
                } catch (\InvalidArgumentException $exception) {
                    $validator->errors()->add('end_date', $exception->getMessage());
                }
            },
        ];
    }

    /**
     * Intervalo validado. Só chamar depois que a validação passou.
     */
    public function toRange(): CalendarRange
    {
        return CalendarRange::fromIso(
            (string) $this->validated('start_date'),
            (string) $this->validated('end_date'),
        );
    }

    /**
     * Filtro de providers pedido; `null` significa "todos os conectados".
     *
     * @return array<int, OauthProvider>|null
     */
    public function providers(): ?array
    {
        if (! $this->has('providers')) {
            return null;
        }

        $values = $this->validated('providers', []);

        if (! is_array($values) || $values === []) {
            return null;
        }

        return array_values(array_filter(array_map(
            static fn (string $value): ?OauthProvider => OauthProvider::tryFrom($value),
            $values,
        )));
    }

    public function search(): ?string
    {
        $search = $this->validated('search');

        return is_string($search) && trim($search) !== '' ? trim($search) : null;
    }
}
