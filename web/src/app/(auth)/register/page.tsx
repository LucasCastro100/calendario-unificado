import Link from 'next/link'
import { redirect } from 'next/navigation'
import type { Metadata } from 'next'
import { CalendarDays } from 'lucide-react'

import { CredentialsForm } from '@/components/auth/credentials-form'
import { currentUser } from '@/services/authService'

/**
 * Tela de registro.
 *
 * Server Component, como o login. Depois do `POST /api/auth/register` a
 * sessão já nasce ativa — o Laravel abre a sessão no mesmo request —, então o
 * redirecionamento para `/calendar` não exige um segundo passo.
 */

export const metadata: Metadata = {
  title: 'Criar conta',
  description: 'Crie sua conta e unifique seus calendários.',
}

export default async function RegisterPage() {
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
            <h1 className="text-2xl font-semibold tracking-tight">Criar conta</h1>
            <p className="text-muted-foreground text-sm">
              Conecte suas contas de calendário quando quiser.
            </p>
          </div>
        </div>

        <CredentialsForm mode="register" />

        <p className="text-muted-foreground text-center text-sm">
          Já tem conta?{' '}
          <Link href="/login" className="text-primary font-medium hover:underline">
            Entrar
          </Link>
        </p>
      </div>
    </main>
  )
}