<?php

declare(strict_types=1);

namespace App\Http\Requests\Calendar;

use App\Enums\OauthProvider;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Exclusão de evento.
 *
 * Não valida body: um DELETE não leva payload, e reaproveitar o
 * `UpdateCalendarEventRequest` exigiria título/início/fim do cliente só para
 * apagar — o frontend teria que enviar dados que não usa.
 *
 * Cobre apenas o que vem da rota: provider conhecido e eventId não vazio.
 * A autorização real é o próprio escopo por `user_id` na query da conexão.
 */
final class DestroyCalendarEventRequest extends FormRequest
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
            'eventId' => ['required', 'string', 'max:512'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $provider = $this->route('provider');

            // Valida o path param aqui: não há campo no body para `Rule::in`.
            if (! is_string($provider) || OauthProvider::tryFrom($provider) === null) {
                $validator->errors()->add('provider', 'Provedor de calendário inválido.');
            }
        });
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
