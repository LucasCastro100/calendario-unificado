'use client'

import { useQuery } from '@tanstack/react-query'

import { calendarEventsResponseSchema } from '@/schemas/calendarSchema'
import type { CalendarEventsResponse, CalendarProvider } from '@/types/calendar'

/**
 * Hook de leitura do calendário unificado.
 *
 * O `queryFn` busca no Route Handler do Next (`/api/calendar/events`), nunca
 * direto no Laravel: o browser não conhece a URL da API nem envia cookie
 * cross-site. A validação Zod acontece no service do servidor, então aqui o
 * dado já vem tipado.
 *
 * A chave é `['unified-calendar', startDate, endDate]`. As datas vão em ISO,
 * não em `Date`, porque `Date` não é serializável e o TanStack compara a chave
 * por igualdade estrutural.
 */

export interface UseUnifiedCalendarParams {
  /** `null` enquanto o calendário ainda não informou o que está visível. */
  startDate: Date | null
  endDate: Date | null
  providers?: CalendarProvider[]
  search?: string
}

export function unifiedCalendarKey(
  startDate: string,
  endDate: string,
  providers?: string,
  search?: string,
): readonly [string, string, string, string?, string?] {
  return ['unified-calendar', startDate, endDate, providers, search] as const
}

export function useUnifiedCalendar({
  startDate,
  endDate,
  providers,
  search,
}: UseUnifiedCalendarParams) {
  const enabled = startDate !== null && endDate !== null
  const start = startDate?.toISOString() ?? ''
  const end = endDate?.toISOString() ?? ''

  return useQuery<CalendarEventsResponse>({
    queryKey: unifiedCalendarKey(start, end, providers?.join(','), search),
    // Sem intervalo conhecido não há requisição: buscar com um palpite
    // errado gasta cota do provider e depois é descartado.
    enabled,
    queryFn: async ({ signal }) => {
      const query = new URLSearchParams({ start_date: start, end_date: end })

      for (const provider of providers ?? []) {
        query.append('providers[]', provider)
      }

      if (search) {
        query.set('search', search)
      }

      const response = await fetch(`/api/calendar/events?${query.toString()}`, {
        signal,
        headers: { Accept: 'application/json' },
      })

      const payload: unknown = await response.json().catch(() => null)

      if (!response.ok) {
        throw Object.assign(
          new Error(readErrorMessage(payload)),
          // `status` é lido pelo `retry` do QueryClient para não repetir em 4xx.
          { status: response.status },
        )
      }

      return calendarEventsResponseSchema.parse(payload)
    },
    // Preserva os eventos anteriores até a resposta nova chegar: sem isso,
    // navegar de mês em mês piscaria um skeleton a cada clique.
    placeholderData: (previous) => previous,
  })
}

function readErrorMessage(payload: unknown): string {
  if (
    typeof payload === 'object' &&
    payload !== null &&
    'message' in payload &&
    typeof (payload as { message: unknown }).message === 'string'
  ) {
    return (payload as { message: string }).message
  }

  return 'Não foi possível carregar os eventos.'
}
