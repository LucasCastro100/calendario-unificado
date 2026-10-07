import { z } from 'zod'

/**
 * Espelho da serialização de `App\Http\Controllers\Api\AuthController`.
 *
 * `AuthController::userPayload()` devolve exatamente estes três campos, e
 * nenhuma credencial — nem senha, nem token, nem session ID. O schema falha se
 * o backend começar a devolver algo a mais, que é o aviso para revisar o
 * controller.
 */

export const authUserSchema = z.object({
  id: z.number().int().positive(),
  name: z.string(),
  email: z.string(),
})

export type AuthUserPayload = z.infer<typeof authUserSchema>

export const authResponseSchema = z.object({
  data: z.object({
    user: authUserSchema,
  }),
})

export type AuthResponsePayload = z.infer<typeof authResponseSchema>