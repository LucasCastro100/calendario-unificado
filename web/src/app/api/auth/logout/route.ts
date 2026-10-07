import { NextResponse } from 'next/server'

import { logout } from '@/services/authService'
import { errorResponse, jsonWithSession } from '@/lib/http'

/**
 * Logout.
 *
 * A resposta do Laravel é a que **expira** o cookie de sessão, então os cookies
 * voltam e são repassados. Sem isso o browser continua com o cookie válido e o
 * usuário parece deslogado só até recarregar a página.
 *
 * Não redireciona: quem chama decide para onde ir (o botão de sair da UI). O
 * 204 casa com o `AuthController`, que também não devolve corpo.
 */
export async function POST(): Promise<NextResponse> {
  try {
    const setCookies = await logout()

    return jsonWithSession(null, setCookies, { status: 204 })
  } catch (error) {
    return errorResponse(error, 'api/auth/logout')
  }
}