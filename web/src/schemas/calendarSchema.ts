import { z } from 'zod'

/**
 * Espelho do que o Laravel serializa.
 *
 * Cada schema existe para falhar no limite: se o backend mudar um campo e
 * alguém esquecer o frontend, o `parse` estoura aqui em vez de renderizar
 * `undefined` silenciosamente na tela.
 *
 * Referências: `app/DTOs/Calendar/UnifiedEventDTO.php`,
 * `UnifiedAttendeeDTO.php`, `ConnectionStatusDTO.php` e `app/Enums/*`.
 */

export const calendarProviderSchema = z.enum(['google', 'microsoft'])

export const eventStatusSchema = z.enum(['confirmed', 'tentative', 'cancelled'])

export const eventVisibilitySchema = z.enum(['default', 'public', 'private'])

/**
 * ISO 8601. O Laravel emite `toIso8601String()`, então o formato é sempre
 * `YYYY-MM-DDTHH:MM:SS+00:00` — validado aqui em vez de assumido.
 */
const isoDateTimeSchema = z.string().refine((value) => !Number.isNaN(Date.parse(value)), {
  message: 'Data inválida',
})

/**
 * URL http/https ou `null`.
 *
 * O backend já sanitiza, mas o schema repete a regra: o campo é renderizado
 * como `href`, e um `javascript:` escaping passaria como string válida.
 */
const safeUrlSchema = z
  .string()
  .refine((value) => value.startsWith('https://') || value.startsWith('http://'), {
    message: 'URL deve ser http ou https',
  })
  .nullable()

export const attendeeSchema = z.object({
  email: z.string().nullable(),
  name: z.string().nullable(),
  is_organizer: z.boolean(),
  response_status: z.string().nullable(),
})

export type Attendee = z.infer<typeof attendeeSchema>

/**
 * Evento unificado.
 *
 * `id` é o uid estável (`provider:id-remoto`): serve de chave para o React e
 * para deduplicar o mesmo evento que chegou de dois providers.
 */
export const calendarEventSchema = z.object({
  id: z.string(),
  provider: calendarProviderSchema,
  title: z.string(),
  description: z.string().nullable(),
  start_time: isoDateTimeSchema,
  end_time: isoDateTimeSchema,
  meeting_link: safeUrlSchema,
  location: z.string().nullable(),
  color: z.string(),
  remote_id: z.string(),
  calendar_id: z.string().nullable(),
  all_day: z.boolean(),
  status: eventStatusSchema,
  visibility: eventVisibilitySchema,
  web_link: safeUrlSchema,
  response_status: z.string().nullable(),
  organizer: attendeeSchema.nullable(),
  attendees: z.array(attendeeSchema),
})

export type CalendarEventPayload = z.infer<typeof calendarEventSchema>

export const calendarRangeSchema = z.object({
  start: isoDateTimeSchema,
  end: isoDateTimeSchema,
})

export type CalendarRange = z.infer<typeof calendarRangeSchema>

/**
 * Metadados da listagem.
 *
 * `failed_providers` é o que permite aviso honesto: um provider que falhou não
 * pode ser escondido, senão a tela parece vazia quando o problema é do Google.
 */
export const calendarEventsMetaSchema = z.object({
  range: calendarRangeSchema,
  total: z.number().int().nonnegative(),
  search: z.string().nullable(),
  requested_providers: z.array(calendarProviderSchema),
  failed_providers: z.array(calendarProviderSchema),
})

export type CalendarEventsMetaPayload = z.infer<typeof calendarEventsMetaSchema>

export const calendarEventsResponseSchema = z.object({
  data: z.array(calendarEventSchema),
  meta: calendarEventsMetaSchema,
})

export type CalendarEventsPayload = z.infer<typeof calendarEventsResponseSchema>

export const calendarConnectionSchema = z.object({
  provider: calendarProviderSchema,
  label: z.string(),
  connected: z.boolean(),
  account_email: z.string().nullable(),
  scopes: z.array(z.string()),
  can_write: z.boolean(),
  token_expires_at: isoDateTimeSchema.nullable(),
  last_synced_at: isoDateTimeSchema.nullable(),
})

export type CalendarConnectionPayload = z.infer<typeof calendarConnectionSchema>

export const connectionsResponseSchema = z.object({
  data: z.array(calendarConnectionSchema),
  meta: z.object({
    total: z.number().int().nonnegative(),
    connected: z.number().int().nonnegative(),
  }),
})

export type ConnectionsPayload = z.infer<typeof connectionsResponseSchema>
