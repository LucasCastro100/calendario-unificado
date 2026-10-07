import 'server-only'

import { NextResponse } from 'next/server'
import { ZodError, type ZodType } from 'zod'

import { ApiError } from '@/lib/api'

/**
 * Utilidades compartilhadas pelos Route Handlers.
 *
 * Um Route Handler é uma fronteira: entra JSON do browser, sai JSON ou
 * redirect. Estas funções concentram as três decisões que *todo* handler toma
 * — como ler o corpo, como responder erro e como repassar os cookies de
 * sessão — para que nenhum delesohaça de fazer por conta própria.
 */

/**
 * Resposta JSON com os cookies de sessão do Laravel anexados.
 *
 * O Next não compartilha o jar de cookies com a API: cada `Set-Cookie` do
 * Laravel precisa ser copiado explicitamente para a resposta do Route Handler,
 * senão a sessão criada no login nunca chega ao browser.
 */
export function jsonWithSession<T>(
  payload: T,
  setCookies: string[],
  init?: ResponseInit,
): NextResponse {
  const response = NextResponse.json(payload, init)

  for (const cookie of setCookies) {
    response.headers.append('Set-Cookie', cookie)
  }

  return response
}

/**
 * Lê e valida o corpo JSON.
 *
 * A validação do Next existe por conveniência e por mensagem boa; a regra que
 * importa continua sendo a do `FormRequest` no Laravel. O backend é quem
 * decide — o frontend só evita mandar lixo para atravessar a rede.
 */
export async function parseBody<T>(request: Request, schema: ZodType<T>): Promise<T> {
  let raw: unknown

  try {
    raw = await request.json()
  } catch {
    throw new ApiError(422, 'Corpo da requisição inválido.', {
      body: ['Envie um JSON válido.'],
    })
  }

  const result = schema.safeParse(raw)

  if (!result.success) {
    throw new ApiError(422, firstMessage(result.error), fieldErrors(result.error))
  }

  return result.data
}

/**
 * Converte uma falha em resposta JSON sem vazar detalhe interno.
 *
 * `ApiError` já é seguro por construção: a mensagem vem do Laravel, que
 * garante que não contém nada do terceiro. Qualquer outra exceção é bug —
 * o stack trace fica no log do servidor, nunca na resposta.
 */
export function errorResponse(error: unknown, context: string): NextResponse {
  if (error instanceof ApiError) {
    return NextResponse.json(
      { message: error.userMessage, errors: error.errors },
      { status: error.status },
    )
  }

  console.error(`[${context}] falha inesperada`, error)

  return NextResponse.json(
    { message: 'Não foi possível concluir a operação agora.' },
    { status: 500 },
  )
}

function firstMessage(error: ZodError): string {
  return error.issues[0]?.message ?? 'Dados inválidos.'
}

function fieldErrors(error: ZodError): Record<string, string[]> {
  const errors: Record<string, string[]> = {}

  for (const issue of error.issues) {
    const key = issue.path.join('.') || 'body'

    errors[key] = [...(errors[key] ?? []), issue.message]
  }

  return errors
}