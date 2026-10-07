import Link from 'next/link'
import { redirect } from 'next/navigation'
import { ArrowRight, CalendarCheck, CalendarDays, Layers, Lock, Plug } from 'lucide-react'

import { Button } from '@/components/ui/button'
import { currentUser } from '@/services/authService'
import { PROVIDER_COLORS, PROVIDER_LABELS } from '@/types/calendar'

/**
 * Landing page.
 *
 * Server Component. A única decisão que exige servidor é o redirecionamento de
 * quem já tem sessão — sem ele, um usuário logado cairia numa página que só
 * oferece "entrar".
 *
 * As cores dos providers vêm de `PROVIDER_COLORS` (mesma fonte usada na
 * legenda do calendário), para que a landing e a agenda não se contradigam.
 */
const FEATURES = [
  {
    icon: Layers,
    title: 'Duas agendas, uma tela',
    description:
      'Google Workspace e Microsoft 365 juntos, no mesmo mês, sem alternar entre abas.',
  },
  {
    icon: CalendarCheck,
    title: 'Leitura parcial honesta',
    description:
      'Se um provedor falhar, os eventos do outro aparecem e a tela avisa o que faltou.',
  },
  {
    icon: Plug,
    title: 'Conexão sob seu controle',
    description: 'Conecte e desconte cada conta quando quiser. Sem wod de compartilhamento.',
  },
  {
    icon: Lock,
    title: 'Sessão em cookie HTTP-Only',
    description: 'Nenhum token de acesso guardado no navegador. Nada legível por script.',
  },
]

export default async function HomePage() {
  const user = await currentUser()

  if (user !== null) {
    redirect('/calendar')
  }

  return (
    <div className="flex min-h-full flex-col">
      <header className="border-b">
        <div className="mx-auto flex w-full max-w-6xl items-center justify-between px-4 py-3">
          <span className="flex items-center gap-2 font-semibold">
            <CalendarDays aria-hidden className="text-primary size-5" />
            Calendário Unificado
          </span>

          <nav className="flex items-center gap-2">
            <Button asChild variant="ghost" size="sm">
              <Link href="/login">Entrar</Link>
            </Button>
            <Button asChild size="sm">
              <Link href="/register">Criar conta</Link>
            </Button>
          </nav>
        </div>
      </header>

      <main className="flex-1">
        {/* Hero */}
        <section className="mx-auto flex w-full max-w-6xl flex-col items-center gap-6 px-4 py-20 text-center">
          <span className="bg-primary/10 text-primary rounded-full px-3 py-1 text-xs font-medium">
            Google Workspace + Microsoft 365
          </span>

          <h1 className="max-w-3xl text-4xl font-semibold tracking-tight text-balance sm:text-5xl">
            Dois calendários, uma única agenda
          </h1>

          <p className="text-muted-foreground max-w-2xl text-lg text-balance">
            Junte os eventos do Google e do Microsoft 365 em uma tela só, com as cores de
            cada conta para saber de onde veio cada compromisso.
          </p>

          <div className="flex flex-wrap items-center justify-center gap-3">
            <Button asChild size="lg">
              <Link href="/register">
                Começar agora
                <ArrowRight aria-hidden className="size-4" />
              </Link>
            </Button>
            <Button asChild size="lg" variant="outline">
              <Link href="/login">Já tenho conta</Link>
            </Button>
          </div>

          <ul className="mt-4 flex flex-wrap items-center justify-center gap-4 text-sm">
            {Object.entries(PROVIDER_LABELS).map(([provider, label]) => (
              <li key={provider} className="flex items-center gap-2">
                <span
                  aria-hidden
                  className="size-2.5 rounded-full"
                  style={{ backgroundColor: PROVIDER_COLORS[provider as 'google'] }}
                />
                {label}
              </li>
            ))}
          </ul>
        </section>

        {/* Benefícios */}
        <section className="bg-muted/30 border-y">
          <div className="mx-auto grid w-full max-w-6xl gap-6 px-4 py-16 sm:grid-cols-2 lg:grid-cols-4">
            {FEATURES.map((feature) => (
              <article key={feature.title} className="flex flex-col gap-3">
                <span className="bg-background text-primary flex size-10 items-center justify-center rounded-lg border">
                  <feature.icon aria-hidden className="size-5" />
                </span>

                <h2 className="font-semibold">{feature.title}</h2>
                <p className="text-muted-foreground text-sm text-balance">
                  {feature.description}
                </p>
              </article>
            ))}
          </div>
        </section>

        {/* Chamada final */}
        <section className="mx-auto flex w-full max-w-3xl flex-col items-center gap-4 px-4 py-20 text-center">
          <h2 className="text-2xl font-semibold tracking-tight text-balance">
            Pronto para parar de alternar entre duas agendas?
          </h2>

          <p className="text-muted-foreground text-balance">
            Crie a conta, conecte o Google ou o Microsoft e comece a ver tudo junto.
          </p>

          <Button asChild size="lg">
            <Link href="/register">
              Criar minha conta
              <ArrowRight aria-hidden className="size-4" />
            </Link>
          </Button>
        </section>
      </main>

      <footer className="border-t">
        <div className="text-muted-foreground mx-auto w-full max-w-6xl px-4 py-6 text-sm">
          Calendário Unificado — Google Workspace e Microsoft 365 em uma agenda.
        </div>
      </footer>
    </div>
  )
}