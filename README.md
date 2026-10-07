# Calendário Unificado

Aplicação que **agrega calendários do Google Workspace e do Microsoft 365 em uma única agenda**. O usuário conecta as contas via OAuth, e a tela mostra os eventos dos dois provedores lado a lado, com cores por provedor, busca, filtro e criação/edição/exclusão de eventos direto na visão unificada.

O projeto é um **monorepo com dois deploys independentes**: uma **API Laravel 13 (somente JSON)** na raiz do repositório e um **frontend Next.js 16 em `web/`**. O browser nunca fala diretamente com o Laravel — toda requisição passa pelos Route Handlers do Next, que repassam o cookie de sessão do Sanctum server-side. Assim nenhum secret do backend é exposto ao cliente.

## Stack

### Backend (raiz)

| Tecnologia | Versão | Origem |
|---|---|---|
| PHP | `^8.3` | `composer.json` |
| Laravel | `^13.17` | `composer.json` |
| Laravel Sanctum | `^4.0` (auth SPA por cookie) | `composer.json` |
| Laravel Socialite | `^5.31` (OAuth Google/Microsoft) | `composer.json` |
| PHPUnit | `^12.5` (testes) | `composer.json` |
| Larastan / Pint | análise estática e formatação | `composer.json` (dev) |
| SQLite | banco padrão local | `config/database.php` |

### Frontend (`web/`)

| Tecnologia | Versão | Origem |
|---|---|---|
| Next.js | `16.3.8` (App Router, Turbopack) | `web/package.json` |
| React / React DOM | `19.2.8` | `web/package.json` |
| TypeScript | `^5` | `web/package.json` |
| FullCalendar (core, react, daygrid, timegrid, list, interaction) | `^6.1.21` | `web/package.json` |
| TanStack React Query | `^5.104` | `web/package.json` |
| Zod | `^4.6` (validação dos schemas) | `web/package.json` |
| shadcn/ui + Radix UI + Tailwind CSS 4 | — | `web/package.json` |
| pnpm | `11.9` (`packageManager`) | `web/package.json` |

## Funcionalidades

- **Cadastro e login** (`POST /api/auth/register`, `POST /api/auth/login`) com sessão via cookie HTTP-only do Sanctum (sem token no `localStorage`).
- **Conexão OAuth com Google Calendar e Microsoft 365** — redirect para o consentimento, callback que troca o `code` por token (gravado **criptografado** no banco) e desconexão (`DELETE /api/oauth/{provider}`).
- **Agenda unificada**: `GET /api/calendar/events` busca os eventos de **todos os provedores conectados em paralelo** (`Http::pool()`) e devolve um DTO único (`UnifiedEventDTO`) — o frontend nunca vê o payload bruto do Google/Graph.
- **Leitura por provedor**: `GET /api/calendar/{provider}/calendars` e `GET /api/calendar/{provider}/events`.
- **Escrita**: criar (`POST`), atualizar (`PATCH`) e remover (`DELETE`) eventos no provedor escolhido.
- **Janela de busca limitada a 366 dias** por chamada (`CalendarRange::MAX_DAYS`), com `start_date`/`end_date` obrigatórios, filtro opcional por `providers[]` e `search`.
- **Cache de eventos** na tabela `calendar_event_caches` (payload, `starts_at`/`ends_at`, `fetched_at`), indexado por usuário.
- **Renovação automática de token**: middleware `universal.refresh-token` renova os tokens antes da leitura/escrita; na rota unificada ele degrada a listagem de forma parcial, na rota por provedor propaga o erro (422 `PROVIDER_REAUTH_REQUIRED`).
- **Interface**: visualização FullCalendar (mês/semana/dia/lista) com cor por provedor (Google `#4285F4`, Microsoft `#6264A7`), badges de conexão, busca e telas de login/registro.
- **Rate limiting** por usuário (ou IP, quando anônimo): `auth` 10/min, `calendar-read` 120/min, `calendar-write` 30/min, `oauth` 20/min.

## Pré-requisitos

- PHP 8.3+ (o ambiente de desenvolvimento usa 8.4)
- Composer 2
- Node 20+ e **pnpm 11** (`corepack enable`)
- Conta de desenvolvedor no [Google Cloud Console](https://console.cloud.google.com/) (OAuth 2.0) e no [Microsoft Entra Portal](https://entra.microsoft.com/) para os testes de OAuth
- SQLite (padrão) — não exige servidor de banco para rodar local

## Instalação e execução

### 1. Backend (Laravel)

```bash
cd calendario-unificado

# instala dependências, cria .env, gera APP_KEY, migra e builda assets
composer setup
```

Ou manualmente:

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite   # se não existir
php artisan migrate
npm install --ignore-scripts && npm run build
```

Subir a API:

```bash
php artisan serve        # http://127.0.0.1:8000
# ou, com frontend embutido no artisan dev:
composer dev
```

### 2. Frontend (Next.js)

```bash
cd web
cp .env.example .env.local
pnpm install
pnpm dev                 # http://localhost:3000
```

### 3. URLs de redirect OAuth

Registrar nos consoles dos provedores (os valores estão comentados em `.env.example`):

- Google: `{FRONTEND_URL}/api/oauth/google/callback`
- Microsoft: `{FRONTEND_URL}/api/oauth/microsoft/callback`

## Estrutura de pastas

```
calendario-unificado/
├── app/
│   ├── DTOs/Calendar/          # DTOs readonly (UnifiedEventDTO, EventPageDTO, …)
│   ├── Enums/                  # OauthProvider, EventStatus, EventVisibility
│   ├── Exceptions/             # CalendarProviderException (erros de provider)
│   ├── Http/
│   │   ├── Controllers/Api/    # Auth, OAuth, Connection, Calendar
│   │   ├── Middleware/         # UniversalRefreshTokenMiddleware
│   │   ├── Requests/           # FormRequests (Api/ e Calendar/)
│   │   └── Resources/          # CalendarEventResource
│   ├── Models/                 # User, CalendarConnection, CalendarEventCache
│   ├── Policies/               # CalendarConnectionPolicy (anti-IDOR)
│   ├── Providers/              # AppServiceProvider (rate limits) e CalendarServiceProvider (bindings)
│   ├── Services/
│   │   ├── Calendar/           # AbstractCalendarService, UnifiedCalendarService
│   │   ├── Contracts/          # CalendarServiceInterface
│   │   ├── Google/             # GoogleCalendarService (adapter)
│   │   └── Microsoft/          # MicrosoftCalendarService (adapter)
│   └── Support/                # OAuthTokenRefresher, ProviderFailureHandler
├── bootstrap/app.php           # middlewares, render JSON, tradução de erros
├── config/                     # sanctum, database, services (Google/Microsoft), …
├── database/migrations/        # users, cache, jobs, personal_access_tokens,
│                               # calendar_connections, calendar_event_caches
├── routes/
│   ├── api.php                 # contrato HTTP (prefixo /api)
│   ├── web.php                 # apenas a welcome page + health /up
│   └── console.php
├── tests/                      # Unit/ e Feature/ (PHPUnit)
└── web/                        # frontend Next.js 16 (toolchain própria: pnpm)
    ├── src/app/(auth)/         # login, register
    ├── src/app/(dashboard)/    # /calendar
    ├── src/app/api/            # Route Handlers (proxy server-side da API)
    ├── src/components/         # calendar/, auth/, ui/ (shadcn)
    ├── src/hooks/              # useUnifiedCalendar
    ├── src/lib/                # api.ts (client do Laravel), http.ts, utils.ts
    ├── src/schemas/            # Zod (authSchema, calendarSchema)
    ├── src/services/           # authService, calendarService
    └── src/types/              # auth.ts, calendar.ts
```

## API — rotas principais

Todas sob `/api` e, exceto autenticação, protegidas por `auth:sanctum`.

| Método | Rota | Descrição |
|---|---|---|
| POST | `/api/auth/register` | cria usuário |
| POST | `/api/auth/login` | login |
| POST | `/api/auth/logout` | encerra sessão |
| GET | `/api/auth/me` | usuário autenticado |
| GET | `/api/connections` | conexões de calendário do usuário |
| GET | `/api/oauth/{provider}/redirect` | inicia OAuth (Google/Microsoft) |
| GET | `/api/oauth/{provider}/callback` | troca `code` por token |
| DELETE | `/api/oauth/{provider}` | desconecta e apaga tokens |
| GET | `/api/calendar/events` | eventos agregados de todos os provedores |
| GET | `/api/calendar/{provider}/calendars` | calendários do provedor |
| GET | `/api/calendar/{provider}/events` | eventos de um provedor |
| POST | `/api/calendar/{provider}/events` | cria evento |
| PATCH | `/api/calendar/{provider}/events/{eventId}` | atualiza evento |
| DELETE | `/api/calendar/{provider}/events/{eventId}` | remove evento |

`{provider}` ∈ `google` \| `microsoft` (validado por `FormRequest`; outro valor → 422).

Respostas de sucesso: `{ "data": [...], "meta": { "range", "total", "providers" } }`. Erros: `{ "message", "errors" }`, com `code` estável para falhas de provedor (`PROVIDER_REAUTH_REQUIRED`, `PROVIDER_RATE_LIMITED`, `PROVIDER_ERROR`).

## Variáveis de ambiente

### Backend — `.env` (chaves relevantes; só nomes)

| Chave | Finalidade |
|---|---|
| `APP_NAME`, `APP_ENV`, `APP_KEY`, `APP_DEBUG`, `APP_URL` | padrão Laravel |
| `DB_CONNECTION`, `DB_DATABASE`, `DB_HOST`, `DB_PORT`, `DB_USERNAME`, `DB_PASSWORD` | banco (SQLite local / MySQL em produção) |
| `SESSION_DRIVER`, `SESSION_DOMAIN`, `SESSION_ENCRYPT`, `SESSION_LIFETIME` | sessão (o `SESSION_DOMAIN` fica `null` de propósito para valer em `:3000` e `:8000`) |
| `CACHE_STORE`, `QUEUE_CONNECTION`, `LOG_CHANNEL` | infraestrutura |
| `FRONTEND_URL` | URL do Next (redirects OAuth) |
| `CORS_ALLOWED_ORIGINS` | origens permitidas |
| `SANCTUM_STATEFUL_DOMAINS` | domínios considerados stateful (ex.: `localhost:3000,localhost:8000`) |
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` | OAuth Google |
| `MICROSOFT_CLIENT_ID`, `MICROSOFT_CLIENT_SECRET`, `MICROSOFT_TENANT` | OAuth Microsoft |

### Frontend — `web/.env.local`

| Chave | Finalidade |
|---|---|
| `LARAVEL_API_URL` | URL da API Laravel (server-side; **não** use prefixo `NEXT_PUBLIC_`) |
| `NEXT_PUBLIC_APP_URL` | URL do próprio Next (usada no `Referer` validado pelo Sanctum) |

## Banco de dados / migrations

```bash
php artisan migrate
```

| Tabela | Conteúdo |
|---|---|
| `users`, `sessions`, `cache`, `jobs` | padrão Laravel |
| `personal_access_tokens` | Sanctum |
| `calendar_connections` | uma conexão por usuário+provedor (`unique(user_id, provider)`), tokens, scopes, `calendar_ids`, `token_expires_at`, `last_synced_at` |
| `calendar_event_caches` | cache de eventos (`remote_id`, `uid`, `payload`, `starts_at`, `ends_at`, `fetched_at`), `unique(user_id, uid)` |

Banco padrão local: **SQLite** (`database/database.sqlite`).

## Testes e verificações

```bash
# Backend
vendor/bin/pint              # formata
vendor/bin/pint --test       # checa formatação
vendor/bin/phpstan analyse   # análise estática
php artisan test             # PHPUnit (Unit/ e Feature/)

# Frontend
cd web && pnpm lint && pnpm typecheck && pnpm build
```

> **Não usar Pest:** a suíte roda em **PHPUnit 12** — o plugin Pest atual não suporta Laravel 13. Escrever testes com `extends TestCase` e atributos `#[Test]`.

## Observações importantes

- **Tokens OAuth nunca em texto puto**: os casts do `CalendarConnection` são `encrypted`; nada de token em log, URL ou response.
- **O browser não fala com o Laravel**: só com os Route Handlers em `web/src/app/api/*`, que repassam o cookie `sanctum_session` e copiam os `Set-Cookie` de volta para a resposta.
- **Adicionar um novo provedor** = criar o adapter em `app/Services/{Provider}/`, mapear para `UnifiedEventDTO` e registrar o binding no `CalendarServiceProvider` a partir do enum `OauthProvider` — sem `if` de provider espalhado pelo código.
- Documentação viva do projeto em `.opencode/memory/` (`architecture.md`, `tech-stack.md`, `security-rules.md`).
- Health check da API: `GET /up`.
- Não há rota de reset de senha implementada.

## Licença

MIT (`composer.json` → `"license": "MIT"`).
