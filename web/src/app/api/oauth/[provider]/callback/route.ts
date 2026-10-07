import { NextResponse } from 'next/server'

import { apiRequest, ApiError } from '@/lib/api'
import { errorResponse } from '@/lib/http'

/**
 * Callback do OAuth.
 *
 * O `redirect_uri` cadastrado no Google e no Azure aponta para **esta** rota,
 * não para o Laravel: o provedor devolve o browser ao Next, que repassa
 * `code` e `state` à API com o cookie de sessão e, em seguida, manda o usuário
 * de volta para a aplicação.
 *
 * O redirecionamento final é sempre para o calendário. O resultado — sucesso,
 * consentimento negado ou token expirado — viaja na query string para a tela
 * mostrar, em vez de virar um erro HTTP: para o usuário, o consentimento
 * recusado é um desfecho previsto, não uma falha de requisição.
 */

const VALID_PROVIDERS = new Set(['google', 'microsoft'])

/** Destino pós-callback. Aba de conexões quando o usuário foi mandado por lá. */
function destination(provider: string, outcome: string): string {
  const params = new URLSearchParams({ oauth: outcome, provider })

  return `/calendar?${params.toString()}`
}

export async function GET(
  request: Request,
  context: RouteContext<'/api/oauth/[provider]/callback'>,
): Promise<NextResponse> {
  const { provider } = await context.params
  const { searchParams } = new URL(request.url)

  if (!VALID_PROVIDERS.has(provider)) {
    return NextResponse.json(
      {
        message: 'Provedor de calendário desconhecido.',
        errors: { provider: [`Inválido: ${provider}`] },
      },
      { status: 422 },
    )
  }

  const query: Record<string, string | undefined> = {
    code: searchParams.get('code') ?? undefined,
    state: searchParams.get('state') ?? undefined,
    error: searchParams.get('error') ?? undefined,
    error_description: searchParams.get('error_description') ?? undefined,
  }

  try {
    const result = await apiRequest<unknown>(`/oauth/${provider}/callback`, { query })

    // A sessão pode ter sido renovada no caminho; repassar mantém o usuário
    // logado sem depender de outra ida ao Laravel.
    const response = NextResponse.redirect(destination(provider, 'connected'))

    for (const cookie of result.setCookies) {
      response.headers.append('Set-Cookie', cookie)
    }

    return response
  } catch (error) {
    const outcome = classify(error)

    return NextResponse.redirect(destination(provider, outcome))
  }
}

/**
 * Traduz a falha em um desfecho legível na query string.
 *
 * `message` do Laravel nunca chega ao browser aqui: é detalhe de validação ou
 * erro do provedor, e o usuário não tem como agir sobre ele. O que ele precisa
 * saber é a categoria — recusou, reconectar ou tentar de novo.
 */
function classify(error: unknown): string {
  if (!(error instanceof ApiError)) {
    return 'error'
  }

  // 422 com `error` preenchido é consentimento negado — resultado legítimo, e
  // o `OAuthController` o trata assim.
  if (error.status === 422 && error.errors.error !== undefined) {
    return 'denied'
  }

  // 401 e 422 com `state` são sessão/state inválido: o `state` é atrelado ao
  // usuário que iniciou, então normalmente significa que a sessão mudou entre
  // o redirect e o retorno do provedor.
  if (error.status === 401 || error.errors.state !== undefined) {
    return 'expired'
  }

  if (error.status === 422) {
    return 'reauth'
  }

  return 'error'
}