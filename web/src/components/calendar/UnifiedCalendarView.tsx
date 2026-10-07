'use client'

import { useCallback, useMemo, useState } from 'react'
import FullCalendar from '@fullcalendar/react'
import dayGridPlugin from '@fullcalendar/daygrid'
import timeGridPlugin from '@fullcalendar/timegrid'
import interactionPlugin from '@fullcalendar/interaction'
import listPlugin from '@fullcalendar/list'
import ptBrLocale from '@fullcalendar/core/locales/pt-br'
import type { DatesSetArg, EventClickArg, EventInput } from '@fullcalendar/core'
import { MapPin, Search, Video } from 'lucide-react'

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Skeleton } from '@/components/ui/skeleton'
import { useUnifiedCalendar } from '@/hooks/useUnifiedCalendar'
import {
  PROVIDER_COLORS,
  PROVIDER_LABELS,
  type CalendarEvent,
  type CalendarProvider,
} from '@/types/calendar'

/**
 * Calendário unificado em FullCalendar.
 *
 * Este é o único ponto com `'use client'` da rota: a página é Server Component
 * e só a agenda precisa do browser. O FullCalendar v6 injeta o próprio CSS,
 * então não há import de `.css` — o contrário conflitaria com o Tailwind.
 */

const PROVIDERS = Object.keys(PROVIDER_LABELS) as CalendarProvider[]

/**
 * Normaliza o DTO do Laravel para o formato do FullCalendar.
 *
 * A cor vem do próprio evento (`color`), não de um mapa local: o backend
 * conhece a identidade visual de cada provider e mudá-la não deve exigir
 * deploy do frontend.
 */
function toEventInput(event: CalendarEvent): EventInput {
  return {
    id: event.id,
    title: event.title,
    start: event.start_time,
    // O Laravel já normaliza fim >= início; o fallback protege contra um
    // evento corrompido derrubar o calendário inteiro.
    end: event.end_time || event.start_time,
    allDay: event.all_day,
    backgroundColor: event.color,
    borderColor: event.color,
    // Cancelado não se edita — reflete a regra do `UpdateCalendarEventRequest`.
    editable: event.status !== 'cancelled',
    extendedProps: event,
  }
}

export function UnifiedCalendarView() {
  // O FullCalendar avisa o intervalo visível via `datesSet`; a busca usa
  // exatamente esse intervalo, para nunca pedir eventos fora da tela.
  const [range, setRange] = useState<{ start: Date; end: Date } | null>(null)
  const [search, setSearch] = useState('')
  const [hidden, setHidden] = useState<CalendarProvider[]>([])

  const handleDatesSet = useCallback((arg: DatesSetArg) => {
    setRange({ start: arg.start, end: arg.end })
  }, [])

  // Filtro de provider vai no servidor: ele evita a chamada e o cache por
  // provider fica correto, em vez de o browser receber tudo e descartar.
  const requested = useMemo(
    () => PROVIDERS.filter((provider) => !hidden.includes(provider)),
    [hidden],
  )

  // Sem intervalo ainda (antes do primeiro `datesSet`) não há busca: pedir com
  // um intervalo estimado gastaria cota do provider para logo ser descartado.
  const { data, isPending, isError, error } = useUnifiedCalendar({
    startDate: range?.start ?? null,
    endDate: range?.end ?? null,
    providers: requested,
    search: search || undefined,
  })

  const events = useMemo(() => (data?.data ?? []).map(toEventInput), [data])
  const failedProviders = data?.meta.failed_providers ?? []

  const toggleProvider = useCallback((provider: CalendarProvider) => {
    setHidden((current) =>
      current.includes(provider)
        ? current.filter((item) => item !== provider)
        : [...current, provider],
    )
  }, [])

  const handleEventClick = useCallback((arg: EventClickArg) => {
    const event = arg.event.extendedProps as CalendarEvent
    // Preferir a call: é o que o usuário quer alcançar num evento com
    // videoconferência. `noopener` para o link não dar acesso a
    // `window.opener` de Google/Outlook.
    const link = event.meeting_link ?? event.web_link

    if (link) {
      window.open(link, '_blank', 'noopener,noreferrer')
    }
  }, [])

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <div className="relative w-full md:max-w-xs">
          <Search
            aria-hidden
            className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2"
          />
          <input
            type="search"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder="Buscar por título ou local"
            aria-label="Buscar eventos"
            className="border-input bg-card h-10 w-full rounded-lg border pr-3 pl-9 text-sm shadow-xs transition-shadow focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
          />
        </div>

        <ProviderFilterLegend hidden={hidden} onToggle={toggleProvider} />
      </div>

      {failedProviders.length > 0 && (
        <Alert>
          <AlertTitle>Leitura parcial</AlertTitle>
          <AlertDescription className="flex flex-wrap items-center gap-2">
            <span>
              Não foi possível sincronizar{' '}
              {failedProviders.map((provider) => PROVIDER_LABELS[provider]).join(' e ')}. Os
              eventos abaixo podem estar incompletos.
            </span>
            {failedProviders.map((provider) => (
              <Badge
                key={provider}
                variant="outline"
                style={{ borderColor: PROVIDER_COLORS[provider], color: PROVIDER_COLORS[provider] }}
              >
                {PROVIDER_LABELS[provider]} indisponível
              </Badge>
            ))}
          </AlertDescription>
        </Alert>
      )}

      {isError && (
        <Alert variant="destructive">
          <AlertTitle>Erro ao carregar</AlertTitle>
          <AlertDescription>
            {error instanceof Error ? error.message : 'Tente novamente em instantes.'}
          </AlertDescription>
        </Alert>
      )}

      {isPending ? (
        <CalendarSkeleton />
      ) : (
        <FullCalendar
          plugins={[dayGridPlugin, timeGridPlugin, interactionPlugin, listPlugin]}
          initialView="dayGridMonth"
          locale={ptBrLocale}
          headerToolbar={{
            left: 'prev,next hoje',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek',
          }}
          buttonText={{
            today: 'Hoje',
            month: 'Mês',
            week: 'Semana',
            day: 'Dia',
            list: 'Lista',
          }}
          events={events}
          datesSet={handleDatesSet}
          eventClick={handleEventClick}
          nowIndicator
          height="auto"
          dayMaxEvents={3}
          displayEventTime
          eventTimeFormat={{ hour: '2-digit', minute: '2-digit', hour12: false }}
          slotLabelFormat={{ hour: '2-digit', minute: '2-digit', hour12: false }}
          eventContent={(arg) => <EventContent event={arg.event.extendedProps as CalendarEvent} />}
        />
      )}

      {data && (
        <p className="text-muted-foreground text-sm">
          {data.meta.total} evento(s)
        </p>
      )}
    </div>
  )
}

/**
 * Legenda de filtro: um checkbox por provider, marcado com a cor que o
 * calendário usa. É o controle que esconde uma fonte sem precisar abrir
 * as configurações da agenda.
 */
function ProviderFilterLegend({
  hidden,
  onToggle,
}: {
  hidden: CalendarProvider[]
  onToggle: (provider: CalendarProvider) => void
}) {
  return (
    <fieldset className="flex flex-wrap items-center gap-2">
      <legend className="text-muted-foreground sr-only">Filtrar por conta</legend>

      {PROVIDERS.map((provider) => {
        const checked = !hidden.includes(provider)

        return (
          <label
            key={provider}
            className="border-input bg-card flex cursor-pointer items-center gap-2 rounded-full border py-1.5 pr-3 pl-2.5 text-sm font-semibold transition-colors hover:bg-accent"
            style={{ opacity: checked ? 1 : 0.45 }}
          >
            <input
              type="checkbox"
              checked={checked}
              onChange={() => onToggle(provider)}
              className="peer sr-only"
            />
            <span
              aria-hidden
              className="size-2.5 rounded-full ring-offset-2 peer-focus-visible:ring-2 peer-focus-visible:ring-ring"
              style={{ backgroundColor: PROVIDER_COLORS[provider] }}
            />
            {PROVIDER_LABELS[provider]}
          </label>
        )
      })}
    </fieldset>
  )
}

/**
 * Conteúdo do evento: ícone de call quando existe videoconferência.
 *
 * O `meeting_link` decide a ação do clique, então o ícone aqui é a mesma
 * informação — evita o usuário descobrir a call só depois de clicar.
 */
function EventContent({ event }: { event: CalendarEvent }) {
  return (
    <span className="flex items-center gap-1 overflow-hidden">
      <span className="truncate">{event.title}</span>
      {event.meeting_link && <Video aria-label="Reunião online" className="size-3 shrink-0" />}
      {!event.meeting_link && event.location && (
        <MapPin aria-label="Com local" className="size-3 shrink-0" />
      )}
    </span>
  )
}

function CalendarSkeleton() {
  return (
    <div className="flex flex-col gap-4" aria-busy="true" aria-label="Carregando calendário">
      <div className="flex items-center gap-2">
        <Skeleton className="size-9" />
        <Skeleton className="size-9" />
        <Skeleton className="h-8 w-40" />
      </div>
      <Skeleton className="h-[600px] w-full" />
    </div>
  )
}
