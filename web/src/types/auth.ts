/**
 * Contrato de autenticação.
 *
 * Tipos derivados do schema Zod (`@/schemas/authSchema`), nunca escritos à
 * mão: se o `AuthController` mudar a assinatura do payload, o compilador aponta
 * o ajuste exato.
 */

import type { z } from 'zod'

import type { authResponseSchema, authUserSchema } from '@/schemas/authSchema'

export type AuthUser = z.infer<typeof authUserSchema>

export type AuthResponse = z.infer<typeof authResponseSchema>

/** Credenciais do formulário de login. */
export interface LoginCredentials {
  email: string
  password: string
}

/** Campos do formulário de registro. `password_confirmation` é exigida pelo Laravel. */
export interface RegisterCredentials {
  name: string
  email: string
  password: string
  password_confirmation: string
}