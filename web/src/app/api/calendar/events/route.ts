import { NextResponse } from 'next/server'
import type { z } from 'zod'

import { ApiError } from '@/lib/api'
import { listCalendarEvents } from '@/services/calendarService'
import type { calendarProviderSchema } from '@/schemas/calendarSchema'

/**
 * Proxy de leitura de eventos.
 *
 * O browser chama este Route Handler; ele roda no servidor Next e repassa o
 * cookie de sessão ao Laravel (`apiFetch` faz isso). Motivo: a API fica em
 * outra origem e o Sanctum stateful depende do cookie — fazer isso no browser
 * exigiria CORS com credenciais e exporia a URL da API.
 *
 * O `cookies()` dentro de `apiFetch` já torna a rota dinâmica, e nesta versão
 * Route Handlers não são cacheados por padrão — por isso não há
 * `export const dynamic` aqui. O `no-store` do fetch cobre a camada HTTP.
 */

const VALID_PROVIDERS = new Set(['google', 'microsoft'])

/**
 * Erro de domínio, para não vazar stack trace ou detalhe de terceiro.
 */
function errorResponse(error: unknown): NextResponse {
  if (error instanceof ApiError) {
    return NextResponse.json(
      { message: error.userMessage, errors: error.errors },
      { status: error.status },
    )
  }

  // Contrato quebrado ou bug inesperado: 500 genérico. O detalhe fica no log
  // do servidor, nunca na resposta.
  console.error('[api/calendar/events] falha inesperada', error)

  return NextResponse.json(
    { message: 'Não foi possível carregar os eventos agora.' },
    { status: 500 },
  )
}

function validationError(message: string, errors: Record<string, string[]>): NextResponse {
  return NextResponse.json({ message, errors }, { status: 422 })
}

export async function GET(request: Request): Promise<NextResponse> {
  const { searchParams } = new URL(request.url)

  // Mesmos nomes do `GetEventsRequest` do Laravel: a query e o evento usam
  // `start_date`/`end_time`, e a distinção é explícita nos dois lados.
  const startDate = searchParams.get('start_date')
  const endDate = searchParams.get('end_date')
  const search = searchParams.get('search') ?? undefined
  const providers = searchParams.getAll('providers[]')

  if (startDate === null || endDate === null) {
    return validationError('Informe o início e o fim do intervalo.', {
      start_date: ['Parâmetro obrigatório.'],
      end_date: ['Parâmetro obrigatório.'],
    })
  }

  const start = new Date(startDate)
  const end = new Date(endDate)

  if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) {
    return validationError('Intervalo inválido.', { start_date: ['Data inválida.'] })
  }

  if (end <= start) {
    return validationError('A data de término deve ser posterior à de início.', {
      end_date: ['Posterior ao início.'],
    })
  }

  // Filtra providers aqui para não repassar lixo ao backend. O Laravel também
  // valida, mas a rejeição chegar como 422 do backend não ajuda o usuário.
  const invalid = providers.filter((provider) => !VALID_PROVIDERS.has(provider))

  if (invalid.length > 0) {
    return validationError('Provedor de calendário desconhecido.', {
      providers: [`Inválido: ${invalid.join(', ')}`],
    })
  }

  try {
    const result = await listCalendarEvents({
      start,
      end,
      providers: providers as Array<z.infer<typeof calendarProviderSchema>>,
      search,
    })

    return NextResponse.json(result, {
      headers: { 'Cache-Control': 'private, no-store' },
    })
  } catch (error) {
    return errorResponse(error)
  }
}
