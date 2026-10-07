<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Enums\OauthProvider;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida o retorno do provedor no callback OAuth.
 *
 * O `error` do provider também precisa passar pela validação: sem ele, um
 * consentimento negado geraria "campo code ausente" em vez de uma mensagem útil.
 */
final class OAuthCallbackRequest extends FormRequest
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
            'code' => ['required_without:error', 'string', 'max:2048'],
            'state' => ['required', 'string', 'size:64'],
            'error' => ['required_without:code', 'string', 'max:255'],
            'scope' => ['sometimes', 'string', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required_without' => 'O provedor não retornou o código de autorização.',
            'state.required' => 'Parâmetro state ausente na resposta do provedor.',
            'state.size' => 'Parâmetro state inválido.',
            'error.required_without' => 'Falha na autorização.',
        ];
    }

    public function provider(): OauthProvider
    {
        return OauthProvider::fromInput($this->route('provider'));
    }

    /**
     * O consentimento foi negado pelo usuário?
     */
    public function wasDenied(): bool
    {
        return $this->filled('error');
    }
}
