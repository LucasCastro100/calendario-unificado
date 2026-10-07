import { Badge } from '@/components/ui/badge'
import { listConnections } from '@/services/calendarService'
import { PROVIDER_COLORS, type CalendarConnection } from '@/types/calendar'

/**
 * Estado de conexão por provider, buscado no servidor.
 *
 * Fica no Server Component de propósito: os dados de conexão mudam pouco e
 * não têm dependência de interação. Se virarem interativos (conectar /
 * reconectar), aí sim um client component com mutation.
 *
 * Falha de leitura não pode derrubar a página: a agenda continua útil mesmo
 * sem saber o status das conexões, então o erro vira texto discreto.
 */
export async function ProviderBadges() {
  const connections = await readConnections()

  if (connections === null) {
    return (
      <p className="text-muted-foreground text-sm">
        Não foi possível verificar quais contas estão conectadas.
      </p>
    )
  }

  return (
    <ul className="flex flex-wrap items-center gap-2" aria-label="Contas conectadas">
      {connections.map((connection) => (
        <li key={connection.provider}>
          <Badge
            variant={connection.connected ? 'default' : 'outline'}
            style={
              connection.connected
                ? { backgroundColor: PROVIDER_COLORS[connection.provider] }
                : undefined
            }
            title={
              connection.connected
                ? (connection.account_email ?? connection.label)
                : 'Nenhuma conta conectada'
            }
          >
            {connection.label}
            {connection.connected ? ' conectado' : ' não conectado'}
          </Badge>
        </li>
      ))}
    </ul>
  )
}

/**
 * Só a leitura fica no `try`: montar JSX dentro dele impede o React Compiler
 * de tratar a árvore, e o ganho de um único `try` não paga esse custo.
 *
 * `null` significa "não deu para saber" — a agenda segue utilizável sem essa
 * informação.
 */
async function readConnections(): Promise<CalendarConnection[] | null> {
  try {
    const { data } = await listConnections()

    return data
  } catch (error) {
    // `cookies()` sinaliza "esta rota é dinâmica" lançando `DYNAMIC_SERVER_USAGE`.
    // Isso é controle de fluxo do Next, não erro: engolir aqui impediria a
    // detecção de rota dinâmica e poluiria o log de build. Relançamos.
    if (isDynamicUsage(error)) {
      throw error
    }

    console.error('[ProviderBadges] falha ao ler conexões', error)

    return null
  }
}

function isDynamicUsage(error: unknown): boolean {
  return (
    typeof error === 'object' &&
    error !== null &&
    'digest' in error &&
    (error as { digest?: unknown }).digest === 'DYNAMIC_SERVER_USAGE'
  )
}
