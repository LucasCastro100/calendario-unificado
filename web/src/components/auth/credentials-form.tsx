'use client'

import { useRouter } from 'next/navigation'
import { useState, type FormEvent } from 'react'
import { AlertCircle, Loader2 } from 'lucide-react'

import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'

/**
 * Formulário de login ou registro.
 *
 * Único arquivo com interatividade das duas telas: o resto (Card, Alert,
 * Button) é Server Component e desce como filho. O envio vai para o Route
 * Handler do Next, que repassa o cookie de sessão — o browser nunca fala com
 * o Laravel.
 *
 * Os campos ficam aqui, e não na página, porque o erro de validação chega do
 * servidor **por campo** (`errors.email`). Se a página desenhasse os inputs,
 * ela não teria como repassar esse mapa para dentro deles sem virar client
 * component — e aí a página inteira viraria cliente por causa de um `onSubmit`.
 */

type Mode = 'login' | 'register'

const ENDPOINTS: Record<Mode, string> = {
  login: '/api/auth/login',
  register: '/api/auth/register',
}

interface ErrorPayload {
  message?: string
  errors?: Record<string, string[]>
}

export function CredentialsForm({ mode }: { mode: Mode }) {
  const router = useRouter()
  const [formError, setFormError] = useState<string | null>(null)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({})
  const [isPending, setIsPending] = useState(false)

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()

    setFormError(null)
    setFieldErrors({})
    setIsPending(true)

    const form = new FormData(event.currentTarget)

    const payload: Record<string, string> = {
      email: String(form.get('email') ?? ''),
      password: String(form.get('password') ?? ''),
    }

    // `password_confirmation` só existe no registro. Omitir a chave em vez de
    // mandar vazia é o que a regra `confirmed` do Laravel espera.
    if (mode === 'register') {
      payload.name = String(form.get('name') ?? '')
      payload.password_confirmation = String(form.get('password_confirmation') ?? '')
    }

    try {
      const response = await fetch(ENDPOINTS[mode], {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      })

      if (!response.ok) {
        const error = (await response.json().catch(() => null)) as ErrorPayload | null

        setFormError(error?.message ?? 'Não foi possível concluir. Tente novamente.')
        setFieldErrors(error?.errors ?? {})

        return
      }

      // `push` e não `replace`: voltar do calendário deve levar ao login, e não
      // reintroduzir a tela de login na pilha. O `refresh` é obrigatório porque
      // o layout autenticado é um Server Component — sem ele, a nova sessão só
      // apareceria no próximo carregamento completo.
      router.push('/calendar')
      router.refresh()
    } catch {
      setFormError('Não foi possível falar com o servidor. Tente novamente.')
    } finally {
      setIsPending(false)
    }
  }

  return (
    <form onSubmit={handleSubmit} className="flex flex-col gap-4">
      {formError !== null && (
        <Alert variant="destructive">
          <AlertDescription className="flex items-start gap-2">
            <AlertCircle aria-hidden className="mt-0.5 size-4 shrink-0" />
            <span>{formError}</span>
          </AlertDescription>
        </Alert>
      )}

      {mode === 'register' && (
        <FormField
          label="Nome"
          name="name"
          type="text"
          autoComplete="name"
          required
          error={fieldErrors.name}
        />
      )}

      <FormField
        label="E-mail"
        name="email"
        type="email"
        autoComplete="email"
        required
        error={fieldErrors.email}
      />

      <FormField
        label="Senha"
        name="password"
        type="password"
        // `current-password` no login e `new-password` no registro: o gerenciador
        // de senha do browser oferece a senha certa em cada caso.
        autoComplete={mode === 'login' ? 'current-password' : 'new-password'}
        required
        minLength={mode === 'register' ? 10 : undefined}
        error={fieldErrors.password}
      />

      {mode === 'register' && (
        <FormField
          label="Confirmar senha"
          name="password_confirmation"
          type="password"
          autoComplete="new-password"
          required
          minLength={10}
          error={fieldErrors.password_confirmation}
        />
      )}

      <Button type="submit" disabled={isPending} className="w-full">
        {isPending && <Loader2 aria-hidden className="size-4 animate-spin" />}
        {isPending ? 'Aguarde…' : mode === 'login' ? 'Entrar' : 'Criar conta'}
      </Button>
    </form>
  )
}

interface FieldProps extends Omit<React.ComponentProps<'input'>, 'name'> {
  name: string
  label: string
  error?: string[]
}

/**
 * Campo com a mensagem de erro do servidor.
 *
 * O `aria-invalid` e o `aria-describedby` não são decoração: quem usa leitor de
 * tela precisa ouvir o motivo da recusa, e a cor vermelha sozinha não diz nada.
 */
function FormField({ name, label, error, ...props }: FieldProps) {
  const message = error?.join(' ')

  return (
    <div className="flex flex-col gap-2">
      <label htmlFor={name} className="text-sm font-medium">
        {label}
      </label>

      <input
        id={name}
        name={name}
        aria-invalid={message !== undefined}
        aria-describedby={message !== undefined ? `${name}-error` : undefined}
        className="border-input h-10 w-full rounded-md border bg-transparent px-3 py-2 text-sm focus-visible:ring-ring focus-visible:ring-2 focus-visible:outline-none"
        {...props}
      />

      {message !== undefined && (
        <p id={`${name}-error`} className="text-destructive text-sm">
          {message}
        </p>
      )}
    </div>
  )
}