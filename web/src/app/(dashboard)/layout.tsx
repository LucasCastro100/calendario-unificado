import Link from 'next/link'
import { redirect } from 'next/navigation'
import { CalendarDays } from 'lucide-react'

import { LogoutButton } from '@/components/layout/logout-button'
import { currentUser } from '@/services/authService'

/**
 * Layout das telas autenticadas.
 *
 * Guarda de página: `currentUser()` roda no servidor e, sem sessão, o usuário
 * vai para o login antes de qualquer dado de calendário ser buscado. Fica aqui
 * e não em cada página porque é a mesma regra para todas elas.
 *
 * Server Component — o único trecho com interatividade é o botão de sair.
 */
export default async function DashboardLayout({ children }: LayoutProps<'/'>) {
  const user = await currentUser()

  if (user === null) {
    redirect('/login')
  }

  return (
    <div className="flex min-h-full flex-col">
      <header className="bg-background/80 sticky top-0 z-10 border-b backdrop-blur-md">
        <div className="mx-auto flex w-full max-w-7xl items-center justify-between gap-4 px-4 py-3.5">
          <Link href="/calendar" className="flex items-center gap-2 font-extrabold tracking-tight">
            <span className="bg-primary text-primary-foreground grid size-9 place-items-center rounded-xl">
              <CalendarDays aria-hidden className="size-5" />
            </span>
            Calendário Unificado
          </Link>

          <div className="flex items-center gap-3">
            <span className="text-muted-foreground hidden text-sm sm:inline">{user.email}</span>
            <LogoutButton />
          </div>
        </div>
      </header>

      <main className="mx-auto w-full max-w-7xl flex-1 px-4 py-8">{children}</main>
    </div>
  )
}