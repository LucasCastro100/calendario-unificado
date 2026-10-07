import type { Metadata } from 'next'
import { KeyRound } from 'lucide-react'

import { ProviderBadges } from '@/components/calendar/ProviderBadges'
import { ProviderConnections } from '@/components/calendar/ProviderConnections'
import { UnifiedCalendarView } from '@/components/calendar/UnifiedCalendarView'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card'
import { listConnections } from '@/services/calendarService'

/**
 * Página do calendário.
 *
 * Server Component por padrão: nada de interatividade aqui, só o esqueleto da
 * tela. O que precisa de browser (FullCalendar, busca, filtro, query) fica no
 * `UnifiedCalendarView`. Fica no grupo `(dashboard)` para compartilhar o
 * layout autenticado com as demais telas.
 *
 * O guard de sessão não é desta página: quem garante é o `(dashboard)/layout`,
 * que redireciona para o login. Repetir a checagem aqui só criaria dois lugares
 * para manter em sincronia.
 */

export const metadata: Metadata = {
  title: 'Calendário',
  description: 'Seus eventos do Google e do Microsoft em uma única agenda.',
}

/**
 * Traduz o desfecho do OAuth que voltou no callback.
 *
 * O Route Handler do callback já separou os casos em `oauth=denied|expired|…`,
 * então aqui é só texto. A mensagem do Laravel não vem: o usuário não tem como
 * agir sobre "invalid_grant", e o técnico olha o log do servidor.
 */
const OAUTH_MESSAGES: Record<string, { title: string; description: string }> = {
  denied: {
    title: 'Acesso não concedido',
    description: 'Você recusou o acesso ao calendário. Nenhuma conta foi conectada.',
  },
  expired: {
    title: 'Conexão expirada',
    description:
      'A tentativa de conexão não pôde ser confirmada. Inicie o processo novamente.',
  },
  reauth: {
    title: 'Reconexão necessária',
    description:
      'A credencial do provedor não foi aceita. Desconecte e conecte a conta de novo.',
  },
  error: {
    title: 'Falha ao conectar',
    description: 'Não foi possível concluir a conexão com o provedor. Tente novamente.',
  },
}

export default async function CalendarPage({
  searchParams,
}: PageProps<'/calendar'>) {
  const params = await searchParams
  const oauth = typeof params.oauth === 'string' ? params.oauth : undefined
  const notice = oauth !== undefined ? OAUTH_MESSAGES[oauth] : undefined

  // Uma item por provider, conectado ou não — os cards de conectar/desconectar
  // precisam do estado dos dois lados, e essa leitura é no servidor: o estado de
  // conexão muda pouco e não depende de interação.
  const { data: connections } = await listConnections()

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-2">
        <h1 className="text-4xl font-extrabold tracking-tight">Calendário</h1>
        <p className="text-muted-foreground">
          Google Workspace e Microsoft 365 em uma única agenda.
        </p>
      </header>

      {notice !== undefined && (
        <Alert variant={oauth === 'connected' ? 'default' : 'destructive'}>
          {oauth === 'connected' ? (
            <>
              <AlertTitle className="flex items-center gap-2">
                <KeyRound aria-hidden className="size-4" />
                Conta conectada
              </AlertTitle>
              <AlertDescription>
                Os eventos dessa conta já aparecem na agenda.
              </AlertDescription>
            </>
          ) : (
            <>
              <AlertTitle>{notice.title}</AlertTitle>
              <AlertDescription>{notice.description}</AlertDescription>
            </>
          )}
        </Alert>
      )}

      <ProviderBadges />

      <div className="grid gap-6 lg:grid-cols-[20rem,1fr] lg:items-start">
        <Card className="shadow-xs">
          <CardHeader>
            <CardTitle>Contas conectadas</CardTitle>
            <CardDescription>
              Conecte o Google Workspace e o Microsoft 365 para juntar os eventos.
            </CardDescription>
          </CardHeader>
          <CardContent>
            <ProviderConnections connections={connections} />
          </CardContent>
        </Card>

        <Card className="shadow-xs">
          <CardHeader>
            <CardTitle>Agenda</CardTitle>
            <CardDescription>
              Navegue entre os meses. Clique em um evento para abrir a reunião ou o
              calendário de origem.
            </CardDescription>
          </CardHeader>
          <CardContent>
            <UnifiedCalendarView />
          </CardContent>
        </Card>
      </div>
    </div>
  )
}