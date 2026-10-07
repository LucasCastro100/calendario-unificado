import { NextResponse } from 'next/server'
import { z } from 'zod'

import { register } from '@/services/authService'
import { errorResponse, jsonWithSession, parseBody } from '@/lib/http'

/**
 * Registro.
 *
 * Mesmo caminho do login: o Laravel cria a conta, abre a sessão e devolve o
 * `Set-Cookie`, que precisa ser repassado ao browser.
 *
 * `password_confirmation` é obrigatório porque o `RegisterRequest` usa a regra
 * `confirmed` — sem o campo de confirmação, o Laravel rejeitaria com 422.
 */

const registerSchema = z
  .object({
    name: z.string().trim().min(2, 'Informe seu nome.').max(120),
    email: z.string().trim().min(1, 'Informe o e-mail.').max(255),
    // O piso de 10 é o mesmo do `RegisterRequest` e do `Password::defaults()`.
    password: z.string().min(10, 'A senha deve ter no mínimo 10 caracteres.').max(255),
    password_confirmation: z.string().min(1, 'Confirme a senha.'),
  })
  .refine((data) => data.password === data.password_confirmation, {
    message: 'A confirmação da senha não confere.',
    path: ['password_confirmation'],
  })

export async function POST(request: Request): Promise<NextResponse> {
  try {
    const credentials = await parseBody(request, registerSchema)
    const { user, setCookies } = await register(credentials)

    return jsonWithSession({ data: { user } }, setCookies, { status: 201 })
  } catch (error) {
    return errorResponse(error, 'api/auth/register')
  }
}