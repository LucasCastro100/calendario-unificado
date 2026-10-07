<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

final class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', 'unique:users,email'],
            // min:10 em vez do padrão 8: credencial de e-mail com acesso a
            // calendário merece um piso maior.
            'password' => ['required', 'string', 'min:10', 'max:255', 'confirmed'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Informe seu nome.',
            'email.unique' => 'Já existe uma conta com este e-mail.',
            'password.confirmed' => 'A confirmação da senha não confere.',
            'password.min' => 'A senha deve ter no mínimo 10 caracteres.',
        ];
    }
}
