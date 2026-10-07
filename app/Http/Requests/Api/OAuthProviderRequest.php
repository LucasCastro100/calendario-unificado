<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Enums\OauthProvider;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida o `{provider}` do path. Valor desconhecido vira 422, não 404 nem 500.
 */
final class OAuthProviderRequest extends FormRequest
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
            'provider' => ['required', 'string', 'in:'.implode(',', OauthProvider::values())],
            'write' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'provider.in' => 'Provedor de calendário inválido.',
        ];
    }

    public function provider(): OauthProvider
    {
        return OauthProvider::fromInput($this->route('provider'));
    }
}
