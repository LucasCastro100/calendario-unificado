'use client'

import { useRouter } from 'next/navigation'
import { useState } from 'react'
import { Loader2, Plug, PlugZap } from 'lucide-react'

import { Alert, AlertDescription } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  PROVIDER_COLORS,
  PROVIDER_LABELS,
  type CalendarConnection,
  type CalendarProvider,
} from '@/types/calendar'

/**
 * Cartões de conexão por provider.
 *
 * Client porque os dois botões são ação: *conectar* navega para a URL de
 * consentimento (o browser precisa sair do site) e *desconectar* é uma
 * requisição com resposta. O estado inicial vem do Server Component pai.
 *
 * O botão de conectar não usa `fetch`: é um `<a>` disfarçado, porque o OAuth
 * exige uma navegação de verdade — um `fetch` seguiria o redirect e traeria o
 * HTML do Google de volta para o Next.
 */
export function ProviderConnections({
  connections,
}: {
  connections: CalendarConnection[]
}) {
  const router = useRouter()
  const [pending, setPending] = useState<CalendarProvider | null>(null)
  const [error, setError] = useState<string | null>(null)

  async function handleDisconnect(provider: CalendarProvider) {
    setPending(provider)
    setError(null)

    try {
      const response = await fetch(`/api/connections?provider=${provider}`, {
        method: 'DELETE',
      })

      if (!response.ok) {
        const payload = (await response.json().catch(() => null)) as {
          message?: string
        } | null

        setError(payload?.message ?? 'Não foi possível desconectar.')

        return
      }

      // `refresh` reconstrói o Server Component pai, que relê as conexões no
      // servidor. Sem ele o card continuaria exibindo "conectado".
      router.refresh()
    } catch {
      setError('Não foi possível falar com o servidor.')
    } finally {
      setPending(null)
    }
  }

  return (
    <div className="flex flex-col gap-4">
      {error !== null && (
        <Alert variant="destructive">
          <AlertDescription>{error}</AlertDescription>
        </Alert>
      )}

      <ul className="grid gap-3">
        {connections.map((connection) => {
          const isPending = pending === connection.provider

          return (
            <li
              key={connection.provider}
              className="bg-card flex items-center justify-between gap-3 rounded-xl border p-4 transition-shadow hover:shadow-xs"
            >
              <div className="flex min-w-0 flex-col gap-1.5">
                <div className="flex items-center gap-2">
                  <span
                    aria-hidden
                    className="size-2.5 shrink-0 rounded-full"
                    style={{ backgroundColor: PROVIDER_COLORS[connection.provider] }}
                  />
                  <span className="font-bold">
                    {connection.label ?? PROVIDER_LABELS[connection.provider]}
                  </span>

                  <Badge variant={connection.connected ? 'default' : 'outline'}>
                    {connection.connected ? 'Conectado' : 'Não conectado'}
                  </Badge>
                </div>

                <span className="text-muted-foreground truncate text-sm">
                  {connection.connected
                    ? (connection.account_email ?? 'Conta conectada')
                    : 'Nenhuma conta conectada'}
                </span>
              </div>

              {connection.connected ? (
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => handleDisconnect(connection.provider)}
                  disabled={isPending}
                >
                  {isPending ? (
                    <Loader2 aria-hidden className="animate-spin" />
                  ) : (
                    <PlugZap aria-hidden />
                  )}
                  Desconectar
                </Button>
              ) : (
                <Button asChild size="sm" disabled={isPending}>
                  <a href={`/api/oauth/${connection.provider}/redirect`}>
                    <Plug aria-hidden />
                    Conectar
                  </a>
                </Button>
              )}
            </li>
          )
        })}
      </ul>
    </div>
  )
}