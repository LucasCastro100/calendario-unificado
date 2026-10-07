import { NextResponse } from 'next/server'

import { apiRedirect, ApiError } from '@/lib/api'
import { errorResponse } from '@/lib/http'

/**
 * Início do fluxo OAuth.
 *
 * O Laravel monta a URL de consentimento e responde 302. Aqui só repassamos o
 * `Location`: o browser precisa ir direto ao Google/Microsoft, então o Next não
 * pode seguir o redirect internamente.
 *
 * A sessão é repassada porque o `OAuthController::redirect()` exige usuário
 * autenticado — o `state` gerado fica atrelado a ele.
 */

const VALID_PROVIDERS = new Set(['google', 'microsoft'])

export async function GET(
  request: Request,
  context: RouteContext<'/api/oauth/[provider]/redirect'>,
): Promise<NextResponse> {
  const { provider } = await context.params
  const { searchParams } = new URL(request.url)
  const write = searchParams.get('write') === 'true' ? 1 : undefined

  try {
    if (!VALID_PROVIDERS.has(provider)) {
      return NextResponse.json(
        {
          message: 'Provedor de calendário desconhecido.',
          errors: { provider: [`Inválido: ${provider}`] },
        },
        { status: 422 },
      )
    }

    const location = await apiRedirect(`/oauth/${provider}/redirect`, { query: { write } })

    if (location !== null) {
      return NextResponse.redirect(location)
    }

    throw new ApiError(
      502,
      'O provedor não respondeu com uma URL de autorização. Verifique as credenciais OAuth.',
    )
  } catch (error) {
    return errorResponse(error, 'api/oauth/[provider]/redirect')
  }
}