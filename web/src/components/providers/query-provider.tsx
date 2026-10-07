'use client'

import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { useState, type ReactNode } from 'react'

/**
 * Provider do TanStack Query, isolado no client.
 *
 * O `QueryClient` é criado dentro de `useState` para que cada requisição do
 * servidor (e cada montagem em dev Strict Mode) receba uma instância nova —
 * módulo em escopo global seria compartilhado entre usuários no SSR e vaza
 * cache de um para o outro.
 */
export function QueryProvider({ children }: { children: ReactNode }) {
  const [queryClient] = useState(
    () =>
      new QueryClient({
        defaultOptions: {
          queries: {
            // Dados de calendário envelhecem rápido: um evento criado no
            // Outlook precisa aparecer sem recarregar a página.
            staleTime: 30_000,
            refetchOnWindowFocus: true,
            retry: (failureCount, error) => {
              // 401/422 não melhoram com retry — repetir só gasta cota da API.
              const status = (error as { status?: number } | undefined)?.status

              if (status === 401 || status === 422 || status === 403) {
                return false
              }

              return failureCount < 2
            },
          },
        },
      }),
  )

  return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
}
