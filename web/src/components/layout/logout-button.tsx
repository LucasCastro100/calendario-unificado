'use client'

import { useRouter } from 'next/navigation'
import { useState } from 'react'
import { LogOut } from 'lucide-react'

import { Button } from '@/components/ui/button'

/**
 * Botão de sair.
 *
 * Client porque a sessão é um cookie: encerrá-la é uma requisição, e a resposta
 * traz o `Set-Cookie` que expira a sessão. Um `<form action>` apontando para o
 * Route Handler também funcionaria, mas exigiria a página inteira como client
 * ou um Server Action — e o logout é a única ação do layout que precisa do
 * browser.
 *
 * O `router.refresh()` é obrigatório: sem ele o layout autenticado, que é um
 * Server Component, continuaria exibindo o usuário até o próximo F5.
 */
export function LogoutButton() {
  const router = useRouter()
  const [isPending, setIsPending] = useState(false)

  async function handleLogout() {
    setIsPending(true)

    try {
      await fetch('/api/auth/logout', { method: 'POST' })
    } finally {
      router.replace('/')
      router.refresh()
      setIsPending(false)
    }
  }

  return (
    <Button variant="ghost" size="sm" onClick={handleLogout} disabled={isPending}>
      <LogOut aria-hidden className="size-4" />
      {isPending ? 'Saindo…' : 'Sair'}
    </Button>
  )
}