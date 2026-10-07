/**
 * Contrato de calendário compartilhado entre o servidor e o cliente.
 *
 * Tipos derivados dos schemas Zod (`@/schemas/calendarSchema`) em vez de
 * escritos à mão: se o backend mudar um campo, o compilador aponta o lugar
 * exato a ajustar em vez de deixar o campo quebrar em silêncio no JSX.
 *
 * As chaves são `snake_case` porque espelham a serialização de
 * `app/DTOs/Calendar/UnifiedEventDTO.php` — não há camada de tradução entre
 * Laravel e Next, e é essa correspondência que o schema Zod garante.
 */

import type { z } from 'zod'

import type {
  attendeeSchema,
  calendarEventSchema,
  calendarEventsMetaSchema,
  calendarEventsResponseSchema,
  calendarProviderSchema,
  calendarConnectionSchema,
  connectionsResponseSchema,
  eventStatusSchema,
  eventVisibilitySchema,
} from '@/schemas/calendarSchema'

/** Provedores OAuth suportados. `OauthProvider` no PHP. */
export type CalendarProvider = z.infer<typeof calendarProviderSchema>

export type EventStatus = z.infer<typeof eventStatusSchema>

export type EventVisibility = z.infer<typeof eventVisibilitySchema>

export type CalendarAttendee = z.infer<typeof attendeeSchema>

/** Evento único, já normalizado entre Google e Microsoft. */
export type CalendarEvent = z.infer<typeof calendarEventSchema>

export type CalendarEventsMeta = z.infer<typeof calendarEventsMetaSchema>

export type CalendarEventsResponse = z.infer<typeof calendarEventsResponseSchema>

export type CalendarConnection = z.infer<typeof calendarConnectionSchema>

export type ConnectionsResponse = z.infer<typeof connectionsResponseSchema>

/** Rótulo curto de exibição. Chaveado pelo provider para não repetir `if`. */
export const PROVIDER_LABELS: Record<CalendarProvider, string> = {
  google: 'Google',
  microsoft: 'Microsoft 365',
}

/**
 * Cor de cada provider no calendário.
 *
 * Os valores vêm de `OauthProvider::color()` no PHP — o backend é a fonte, e
 * o `color` do evento já vem pronto no payload. Aqui só serve para a legenda
 * e para o checkbox de filtro, que existem antes de qualquer evento chegar.
 */
export const PROVIDER_COLORS: Record<CalendarProvider, string> = {
  google: '#4285F4',
  microsoft: '#6264A7',
}
