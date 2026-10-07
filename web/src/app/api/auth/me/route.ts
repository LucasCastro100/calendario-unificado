import { NextResponse } from 'next/server'

import { currentUser } from '@/services/authService'
import { errorResponse, jsonWithSession } from '@/lib/http'

/**
 * Usuário da sessão atual.
 *
 * Sem sessão responde **401**, não 200 com `user: null`: quem consome esta rota
 * precisa distinguir "não logado" de "logado, mas sem nome" — e é esse 401 que
 * o proxy de eventos e a tela autenticada usam para redirecionar ao login.
 */
export async function GET(): Promise<NextResponse> {
  try {
    const user = await currentUser()

    if (user === null) {
      return NextResponse.json({ message: 'Não autenticado.' }, { status: 401 })
    }

    return jsonWithSession({ data: { user } }, [])
  } catch (error) {
    return errorResponse(error, 'api/auth/me')
  }
}