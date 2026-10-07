import type { Metadata } from 'next'
import { Geist_Mono, Nunito } from 'next/font/google'

import { QueryProvider } from '@/components/providers/query-provider'

import './globals.css'

const nunito = Nunito({
  variable: '--font-sans',
  subsets: ['latin'],
  weight: ['400', '500', '600', '700', '800'],
  display: 'swap',
})

const geistMono = Geist_Mono({
  variable: '--font-geist-mono',
  subsets: ['latin'],
})

export const metadata: Metadata = {
  title: 'Calendário Unificado',
  description: 'Seus eventos do Google Workspace e do Microsoft 365 em uma agenda.',
}

export default function RootLayout({ children }: LayoutProps<'/'>) {
  return (
    <html
      lang="pt-BR"
      className={`${nunito.variable} ${geistMono.variable} h-full antialiased`}
    >
      <body className="flex min-h-full flex-col">
        <QueryProvider>{children}</QueryProvider>
      </body>
    </html>
  )
}
