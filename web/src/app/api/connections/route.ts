import { NextResponse } from 'next/server'

import { apiRequest, ApiError } from '@/lib/api'
import { connectionsResponseSchema } from '@/schemas/calendarSchema'
import { parseOrThrow } from '@/services/calendarService'
import { errorResponse } from '@/lib/http'

/**
 * Conexões de calendário para o painel de conexões.
 *
 * `ProviderBadges` (Server Component) lê `/connections` direto pelo service.
 * Esta rota existe para os botões *conectar* e *desconectar*, que precisam
 * reagir sem recarregar a página — e uma Server Action não serviria para
 * conectar, porque o consentimento do OAuth exige o navegador indo ao provedor.
 */

const VALID_PROVIDERS = new Set(['google', 'microsoft'])

export async function GET(): Promise<NextResponse> {
  try {
    const result = await apiRequest<unknown>('/connections')
    const payload = parseOrThrow(
      connectionsResponseSchema,
      result.payload,
      'GET /connections',
    )

    return NextResponse.json(payload, {
      headers: { 'Cache-Control': 'private, no-store' },
    })
  } catch (error) {
    return errorResponse(error, 'api/connections')
  }
}

/**
 * Desconecta um provider.
 *
 * O 404 é o resultado esperado de "já estava desconectado": tratar como erro
 * mostraria uma mensagem vermelha por um clique que não mudou nada.
 */
export async function DELETE(request: Request): Promise<NextResponse> {
  const provider = new URL(request.url).searchParams.get('provider')

  try {
    if (provider === null || !VALID_PROVIDERS.has(provider)) {
      return NextResponse.json(
        {
          message: 'Provedor de calendário desconhecido.',
          errors: { provider: [`Inválido: ${provider ?? '(ausente)'}`] },
        },
        { status: 422 },
      )
    }

    try {
      await apiRequest<undefined>(`/oauth/${provider}`, { method: 'DELETE' })
    } catch (error) {
      if (error instanceof ApiError && error.status === 404) {
        return NextResponse.json({ data: { provider, connected: false } })
      }

      throw error
    }

    return NextResponse.json({ data: { provider, connected: false } })
  } catch (error) {
    return errorResponse(error, 'api/connections')
  }
}