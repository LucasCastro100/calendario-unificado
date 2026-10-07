import 'server-only'

import { apiFetch, ApiError } from '@/lib/api'
import {
  calendarEventsResponseSchema,
  connectionsResponseSchema,
} from '@/schemas/calendarSchema'
import type {
  CalendarEventsResponse,
  CalendarProvider,
  ConnectionsResponse,
} from '@/types/calendar'

/**
 * Acesso aos dados de calendário.
 *
 * Toda saída da API é validada por Zod aqui. O motivo é concreto: o contrato
 * entre PHP e TypeScript não é verificado pelo compilador, e um campo
 * renomeado no DTO do Laravel viraria `undefined` renderizado em silêncio. O
 * `parse` transforma essa divergência em erro explícito, no limite.
 *
 * Só roda no servidor (`server-only`): quem chama é o Route Handler do Next,
 * que repassa o cookie de sessão ao Laravel. O browser nunca vê a URL da API.
 */

export interface ListCalendarEventsParams {
  start: Date
  end: Date
  providers?: CalendarProvider[]
  search?: string
}

/**
 * Eventos unificados no intervalo.
 *
 * Os nomes seguem o `GetEventsRequest` do Laravel (`start_date`/`end_date`).
 * O `meta.failed_providers` vem junto justamente para a UI poder avisar que a
 * tela está parcial — o chamador decide o que fazer com essa lista.
 */
export async function listCalendarEvents({
  start,
  end,
  providers,
  search,
}: ListCalendarEventsParams): Promise<CalendarEventsResponse> {
  const payload = await apiFetch<unknown>('/calendar/events', {
    query: {
      start_date: start.toISOString(),
      end_date: end.toISOString(),
      providers,
      search,
    },
  })

  return parseOrThrow(calendarEventsResponseSchema, payload, 'GET /calendar/events')
}

/**
 * Estado das conexões por provider.
 */
export async function listConnections(): Promise<ConnectionsResponse> {
  const payload = await apiFetch<unknown>('/connections')

  return parseOrThrow(connectionsResponseSchema, payload, 'GET /connections')
}

/**
 * Erro de parsing do contrato: o backend respondeu, mas fora do schema.
 *
 * Distinto de `ApiError` porque a ação certa é diferente — aqui o deploy do
 * backend e o do frontend estão fora de sincronia, não a sessão do usuário.
 */
export class ContractError extends Error {
  constructor(
    message: string,
    readonly issues: unknown,
  ) {
    super(message)
    this.name = 'ContractError'
  }
}

/**
 * Wrapper para traduzir falha de Zod em `ContractError`, com o detalhe das
 * issues no log do servidor — nunca na resposta ao browser.
 */
export function parseOrThrow<T>(
  schema: { parse: (value: unknown) => T },
  payload: unknown,
  context: string,
): T {
  try {
    return schema.parse(payload)
  } catch (error) {
    if (error instanceof ApiError) {
      throw error
    }

    console.error(`[contract] ${context}`, error)

    throw new ContractError('A resposta do servidor está fora do formato esperado.', error)
  }
}
