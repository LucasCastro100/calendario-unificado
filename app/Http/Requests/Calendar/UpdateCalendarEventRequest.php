<?php

declare(strict_types=1);

namespace App\Http\Requests\Calendar;

use App\Enums\EventVisibility;
use App\Enums\OauthProvider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Atualização de evento. Mesma validação da criação — PUT é idêntico por
 * definição, então os campos continuam obrigatórios.
 */
final class UpdateCalendarEventRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:8000'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'start' => ['required', 'string', 'date'],
            'end' => ['required', 'string', 'date', 'after:start'],
            'all_day' => ['sometimes', 'boolean'],
            'visibility' => ['sometimes', 'string', Rule::in(EventVisibility::values())],
            'attendees' => ['sometimes', 'array', 'max:50'],
            'attendees.*' => ['email:rfc', 'max:255'],
            'calendar_id' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Informe o título do evento.',
            'start.required' => 'Informe a data de início.',
            'end.required' => 'Informe a data de término.',
            'end.after' => 'A data de término deve ser posterior à de início.',
            'attendees.*.email' => 'Cada participante deve ser um e-mail válido.',
        ];
    }

    public function provider(): OauthProvider
    {
        return OauthProvider::fromInput($this->route('provider'));
    }

    /**
     * Id do evento **no provedor** (Google/Graph), nunca o id local do cache.
     */
    public function eventId(): string
    {
        return (string) $this->route('eventId');
    }
}
