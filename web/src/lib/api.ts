import 'server-only'

import { cookies, headers } from 'next/headers'

/**
 * Cliente HTTP do Laravel, usado **apenas** no servidor.
 *
 * O browser nunca fala com o Laravel: ele chama um Route Handler do Next, que
 * roda em Node e usa isto. Três consequências:
 *
 * 1. O cookie de sessão do Sanctum precisa ser repassado. Sem `credentials`, o
 *    Laravel veria a requisição como anônima e devolveria 401 mesmo com o
 *    usuário logado.
 * 2. `API_URL` é uma variável de ambiente do servidor. Nunca deve aparecer em
 *    código client-side — `server-only` faz o build falhar se alguém importar
 *    este módulo de um componente cliente.
 * 3. A sessão é criada pelo Laravel, então o `Set-Cookie` volta no mesmo
 *    caminho. Quem chama precisa repassar esses cookies na resposta ao browser —
 *    é o que `apiRequest` devolve em `setCookies`.
 */

/**
 * Erro da API, com o status e os erros de validação já estruturados.
 *
 * Não embute o corpo cru: o corpo pode conter detalhe de terceiro, e é
 * responsabilidade do Laravel já ter filtrado o que sai para o cliente.
 */
export class ApiError extends Error {
  constructor(
    readonly status: number,
    message: string,
    readonly errors: Record<string, string[]> = {},
  ) {
    super(message)
    this.name = 'ApiError'
  }

  /** Mensagem segura para exibir na UI. */
  get userMessage(): string {
    return this.message
  }

  /** Erro de credencial: a UI deve mandar o usuário para a tela de login. */
  get isUnauthenticated(): boolean {
    return this.status === 401
  }
}

const API_URL = process.env.LARAVEL_API_URL ?? 'http://127.0.0.1:8000'

/**
 * Base da API com o prefixo `/api`, sem barra final.
 */
export function apiBaseUrl(): string {
  return `${API_URL.replace(/\/+$/, '')}/api`
}

type Method = 'GET' | 'POST' | 'PATCH' | 'DELETE'

interface RequestOptions {
  method?: Method
  /** Query string. `undefined` e `null` são omitidos. */
  query?: Record<string, string | number | boolean | undefined | null | string[]>
  body?: unknown
  /** URL já absoluta (usado pelo proxy do callback OAuth). */
  absoluteUrl?: string
}

/**
 * Resposta do Laravel com os cookies que ele mandou.
 *
 * O `setCookies` existe porque a sessão mora em cookie: sem repassá-los, o
 * login responderia 200 e o usuário continuaria deslogado na requisição
 * seguinte.
 */
export interface ApiResult<T> {
  payload: T
  setCookies: string[]
}

/**
 * Monta a query string preservando arrays (`providers[]=google&providers[]=microsoft`).
 */
function buildQuery(
  query: RequestOptions['query'] = {},
): string {
  const params = new URLSearchParams()

  for (const [key, value] of Object.entries(query)) {
    if (value === undefined || value === null) {
      continue
    }

    if (Array.isArray(value)) {
      for (const item of value) {
        params.append(`${key}[]`, item)
      }

      continue
    }

    params.append(key, String(value))
  }

  const asString = params.toString()

  return asString === '' ? '' : `?${asString}`
}

/**
 * Headers que o Sanctum usa para decidir se a requisição é "stateful".
 *
 * `EnsureFrontendRequestsAreStateful::fromFrontend()` compara `Referer` ou
 * `Origin` com `SANCTUM_STATEFUL_DOMAINS`. Sem o `Referer` apontando para o
 * Next, o Laravel não liga `StartSession`, o cookie de sessão é ignorado e
 * todo endpoint responde 401 — mesmo com login feito. Como o fetch sai do
 * servidor Next (não do browser), o header não vem sozinho.
 */
async function statefulHeaders(): Promise<Record<string, string>> {
  const incoming = await headers()
  const forwarded: Record<string, string> = {
    Referer: process.env.NEXT_PUBLIC_APP_URL ?? 'http://localhost:3000',
  }

  // `PreventRequestForgery` considera a requisição legítima quando
  // `Sec-Fetch-Site: same-origin`. O browser define esse header sozinho, então
  // repassá-lo é o que preserva a proteção contra CSRF através do proxy: uma
  // página de terceiro chamando a rota do Next enviaria `cross-site` e seria
  // rejeitada pelo Laravel.
  const secFetchSite = incoming.get('sec-fetch-site')

  if (secFetchSite !== null) {
    forwarded['Sec-Fetch-Site'] = secFetchSite
  }

  return forwarded
}

/**
 * Executa a requisição e devolve o payload junto com os cookies de resposta.
 */
export async function apiRequest<T>(
  path: string,
  options: RequestOptions = {},
): Promise<ApiResult<T>> {
  const { method = 'GET', query, body, absoluteUrl } = options

  const url = absoluteUrl
    ? `${absoluteUrl}${buildQuery(query)}`
    : `${apiBaseUrl()}${path}${buildQuery(query)}`

  const cookieStore = await cookies()
  const cookieHeader = cookieStore
    .getAll()
    .map(({ name, value }) => `${name}=${value}`)
    .join('; ')

  let response: Response

  try {
    response = await fetch(url, {
      method,
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(await statefulHeaders()),
        ...(cookieHeader === '' ? {} : { Cookie: cookieHeader }),
        ...(body === undefined ? {} : { 'Content-Type': 'application/json' }),
      },
      // A sessão vive no cookie: sem isto o Next usaria o cache de build e
      // serviria a resposta de outro usuário.
      cache: 'no-store',
      ...(body === undefined ? {} : { body: JSON.stringify(body) }),
    })
  } catch (cause) {
    throw new ApiError(
      503,
      'Não foi possível falar com o servidor. Tente novamente.',
      { connection: [cause instanceof Error ? cause.message : 'erro desconhecido'] },
    )
  }

  const setCookies = response.headers.getSetCookie()

  if (response.status === 204) {
    return { payload: undefined as T, setCookies }
  }

  const payload: unknown = await response.json().catch(() => null)

  if (!response.ok) {
    throw new ApiError(
      response.status,
      extractMessage(payload, response),
      extractErrors(payload),
    )
  }

  return { payload: payload as T, setCookies }
}

/**
 * Atalho para quando o chamador não precisa dos cookies de resposta.
 *
 * Lança `ApiError` em qualquer resposta não-2xx. Nunca devolve `undefined`: um
 * erro de rede é tão relevante quanto um 4xx e silenciá-lo produziria tela
 * vazia sem diagnóstico.
 */
export async function apiFetch<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const { payload } = await apiRequest<T>(path, options)

  return payload
}

/**
 * URL de redirecionamento do Laravel, ou `null` se a resposta não foi redirect.
 *
 * Existe para o OAuth: o consentimento do Google e da Microsoft acontece no
 * navegador, e o fetch do servidor não pode seguir o redirect — buscaria a
 * página de consentimento e devolveria HTML em vez de redirecionar. Então o
 * `Location` é repassado ao browser.
 *
 * Lança `ApiError` quando a resposta é um erro (401, 422 de validação, 500):
 * nesses casos o corpo tem `message` segura e o chamador decide o que fazer.
 */
export async function apiRedirect(
  path: string,
  options: RequestOptions = {},
): Promise<string | null> {
  const { query } = options
  const cookieStore = await cookies()
  const cookieHeader = cookieStore
    .getAll()
    .map(({ name, value }) => `${name}=${value}`)
    .join('; ')

  let response: Response

  try {
    response = await fetch(`${apiBaseUrl()}${path}${buildQuery(query)}`, {
      method: 'GET',
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(await statefulHeaders()),
        ...(cookieHeader === '' ? {} : { Cookie: cookieHeader }),
      },
      redirect: 'manual',
      cache: 'no-store',
    })
  } catch (cause) {
    throw new ApiError(
      503,
      'Não foi possível falar com o servidor. Tente novamente.',
      { connection: [cause instanceof Error ? cause.message : 'erro desconhecido'] },
    )
  }

  const location = response.headers.get('location')

  if (response.status >= 300 && response.status < 400 && location !== null) {
    return location
  }

  const payload: unknown = await response.json().catch(() => null)

  throw new ApiError(
    response.status,
    extractMessage(payload, response),
    extractErrors(payload),
  )
}

/**
 * Mensagem vinda do Laravel, com fallback genérico.
 *
 * O Laravel já garante que `message` é seguro (ver `CalendarProviderException`:
 * o detalhe técnico do terceiro fica no log, não na mensagem).
 */
function extractMessage(payload: unknown, response: Response): string {
  if (typeof payload === 'object' && payload !== null && 'message' in payload) {
    const message = (payload as { message: unknown }).message

    if (typeof message === 'string' && message !== '') {
      return message
    }
  }

  return `Erro ${response.status} ao consultar a API.`
}

function extractErrors(payload: unknown): Record<string, string[]> {
  if (typeof payload !== 'object' || payload === null || !('errors' in payload)) {
    return {}
  }

  const errors = (payload as { errors: unknown }).errors

  if (typeof errors !== 'object' || errors === null) {
    return {}
  }

  return Object.fromEntries(
    Object.entries(errors as Record<string, unknown>).map(([field, messages]) => [
      field,
      Array.isArray(messages) ? messages.map(String) : [String(messages)],
    ]),
  )
}