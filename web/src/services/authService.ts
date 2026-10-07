import 'server-only'

import { apiRequest, ApiError } from '@/lib/api'
import { authResponseSchema } from '@/schemas/authSchema'
import type { AuthUser, LoginCredentials, RegisterCredentials } from '@/types/auth'

/**
 * Autenticação do usuário.
 *
 * Roda no servidor Next, que repassa o cookie de sessão ao Laravel. O browser
 * nunca recebe token: a sessão vive em cookie HTTP-Only emitido pela API.
 *
 * Os métodos de escrita devolvem `setCookies` porque a resposta do login é a
 * que **cria** a sessão — sem repassá-la ao browser, o login apareceria como
 * sucesso e a requisição seguinte seria 401.
 */

export interface AuthResult {
  user: AuthUser
  setCookies: string[]
}

export async function login(credentials: LoginCredentials): Promise<AuthResult> {
  const { payload, setCookies } = await apiRequest<unknown>('/auth/login', {
    method: 'POST',
    body: credentials,
  })

  return { user: parseUser(payload, 'POST /auth/login'), setCookies }
}

export async function register(credentials: RegisterCredentials): Promise<AuthResult> {
  const { payload, setCookies } = await apiRequest<unknown>('/auth/register', {
    method: 'POST',
    body: credentials,
  })

  return { user: parseUser(payload, 'POST /auth/register'), setCookies }
}

/**
 * Encerra a sessão.
 *
 * Devolve os cookies porque a resposta do logout é a que **expira** o cookie de
 * sessão. Sem repassá-la, o cookie continua no browser e o usuário parece
 * deslogado apenas até recarregar.
 */
export async function logout(): Promise<string[]> {
  const { setCookies } = await apiRequest<undefined>('/auth/logout', { method: 'POST' })

  return setCookies
}

/**
 * Usuário da sessão atual, ou `null` quando não há sessão.
 *
 * `null` em vez de exceção: "não autenticado" é o estado esperado de boa parte
 * das telas (landing, redirecionamento para login), não uma falha.
 */
export async function currentUser(): Promise<AuthUser | null> {
  try {
    const payload = await apiRequest<unknown>('/auth/me')

    return parseUser(payload.payload, 'GET /auth/me')
  } catch (error) {
    if (error instanceof ApiError && error.isUnauthenticated) {
      return null
    }

    throw error
  }
}

function parseUser(payload: unknown, context: string): AuthUser {
  const parsed = authResponseSchema.safeParse(payload)

  if (!parsed.success) {
    console.error(`[contract] ${context}`, parsed.error)

    throw new Error('A resposta do servidor está fora do formato esperado.')
  }

  return parsed.data.data.user
}