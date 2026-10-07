import { NextResponse } from 'next/server'
import { z } from 'zod'

import { login } from '@/services/authService'
import { errorResponse, jsonWithSession, parseBody } from '@/lib/http'

/**
 * Login.
 *
 * A sessão é criada pelo Laravel e volta em `Set-Cookie`; `jsonWithSession` a
 * repassa ao browser. Sem isso o POST responderia 200 e a próxima requisição
 * seria 401.
 *
 * O body é validado aqui e no `LoginRequest`: aqui evita tráfego inútil e dá
 * mensagem imediata, lá é a regra que decide.
 */

const loginSchema = z.object({
  email: z.string().min(1, 'Informe o e-mail.').max(255),
  password: z.string().min(1, 'Informe a senha.').max(255),
})

export async function POST(request: Request): Promise<NextResponse> {
  try {
    const credentials = await parseBody(request, loginSchema)
    const { user, setCookies } = await login(credentials)

    return jsonWithSession({ data: { user } }, setCookies)
  } catch (error) {
    return errorResponse(error, 'api/auth/login')
  }
}