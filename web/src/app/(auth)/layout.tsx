import Link from 'next/link'
import { redirect } from 'next/navigation'
import type { ReactNode } from 'react'
import { CalendarDays } from 'lucide-react'

import { currentUser } from '@/services/authService'

/**
 * Layout das telas públicas (login, registro).
 *
 * Redireciona quem já tem sessão: entrar numa tela de login com a sessão ativa
 * não faz sentido e confunde — o usuário clica em "Entrar" e a tela troca
 * sozinha. A checagem mora aqui para que as duas páginas não repitam o mesmo
 * `if`, já que o Route Handler `/login` só roda depois do formulário ser
 * enviado.
 */
export default async function AuthLayout({ children }: { children: ReactNode }) {
  const user = await currentUser()

  if (user !== null) {
    redirect('/calendar')
  }

  return (
    <div className="flex min-h-full flex-col">
      <header className="border-b">
        <div className="mx-auto flex w-full max-w-6xl items-center px-4 py-3">
          <Link href="/" className="flex items-center gap-2 font-semibold">
            <CalendarDays aria-hidden className="text-primary size-5" />
            Calendário Unificado
          </Link>
        </div>
      </header>

      {children}
    </div>
  )
}