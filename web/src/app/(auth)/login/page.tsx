import Link from 'next/link'
import { redirect } from 'next/navigation'
import type { Metadata } from 'next'
import { CalendarDays } from 'lucide-react'

import { CredentialsForm } from '@/components/auth/credentials-form'
import { currentUser } from '@/services/authService'

/**
 * Tela de login.
 *
 * Server Component: só a busca da sessão e o redirecionamento exigem servidor.
 * O formulário é o único trecho com interatividade e mora em arquivo separado.
 *
 * `currentUser()` no servidor usa o mesmo cookie que o browser enviaria, então
 * quem já tem sessão é redirecionado sem piscar a tela de login. É o mesmo
 * mecanismo do layout autenticado — o guard de página fica lá.
 */

export const metadata: Metadata = {
  title: 'Entrar',
  description: 'Acesse seu calendário unificado.',
}

export default async function LoginPage() {
  const user = await currentUser()

  if (user !== null) {
    redirect('/calendar')
  }

  return (
    <main className="bg-muted/30 flex flex-1 items-center justify-center px-4 py-16">
      <div className="w-full max-w-sm space-y-6">
        <div className="flex flex-col items-center gap-3 text-center">
          <span className="bg-primary/10 text-primary flex size-12 items-center justify-center rounded-full">
            <CalendarDays aria-hidden className="size-6" />
          </span>

          <div className="space-y-1">
            <h1 className="text-2xl font-semibold tracking-tight">Entrar</h1>
            <p className="text-muted-foreground text-sm">
              Sua agenda do Google e do Microsoft 365 em um lugar só.
            </p>
          </div>
        </div>

        <CredentialsForm mode="login" />

        <p className="text-muted-foreground text-center text-sm">
          Ainda não tem conta?{' '}
          <Link href="/register" className="text-primary font-medium hover:underline">
            Criar agora
          </Link>
        </p>
      </div>
    </main>
  )
}