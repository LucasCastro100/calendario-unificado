<laravel-boost-guidelines>

# Calendário Unificado — Laravel + Next.js

Monorepo: **Laravel 13 API-only na raiz** + **Next.js 16 em `web/`**.
Agrega calendários do Google Workspace e do Microsoft 365 em uma API e uma tela.

## Estrutura

- API Laravel: raiz do repo (`app/`, `routes/api.php`, `config/`, `database/`).
- Frontend Next.js: `web/` (toolchain próprio — pnpm, tsconfig próprio).
- Memória do projeto: `.opencode/memory/` — **ler antes de qualquer mudança**.

## FERRAMENTAS

Prefira as tools do Boost (MCP) sobre comandos manuais quando disponíveis:
`database-query`, `database-schema`, `search-docs`, `get-absolute-url`, `browser-logs`.
Use `search-docs` antes de fazer mudanças de código — retorna docs versionadas.

## Convenções

- PHP 8.4. Convenções existentes em `app/` prevalecem. Verificar arquivos vizinhos
  antes de criar um novo.
- DTOs são `readonly class` com `toArray()`. É a única forma de serializar para o cliente.
- Services implementam `CalendarServiceInterface`. A implementação concreta é
  resolvida pelo container a partir do enum `CalendarProvider` — nunca `if` de
  provider espalhado pelo código.
- Controllers magros: validar → autorizar → delegar → formatar. Sem regra de negócio.
- Toda entrada passa por `FormRequest`. Proibido `$request->all()`.
- Todo recurso de usuário é protegido por `Policy` (anti-IDOR). Queries sempre
  filtradas por `user_id`.
- Tokens OAuth **nunca** em texto puro, log, URL ou response. Cast `encrypted`.
- Frontend: Server Component por padrão. `'use client'` só no arquivo com
  interatividade. Fetch sempre em `web/src/services/` com parse Zod no service.
- O browser nunca fala direto com o Laravel — sempre via Route Handler do Next.

## Scripts de verificação

Rode **antes** de entregar qualquer mudança:

```bash
# Backend
vendor/bin/pint                    # formatar
vendor/bin/pint --test             # check de formatação
vendor/bin/phpstan analyse         # análise estática
php artisan test                   # Pest

# Frontend
cd web && pnpm lint && pnpm typecheck && pnpm build
```

## Regras de segurança

`.opencode/memory/security-rules.md` é a lista de verificação obrigatória antes de
qualquer alteração que toque OAuth, sessão, upload, dados do usuário ou rotas
autenticadas. Não Ignore.

## Memória

Ao mudar rota, DTO, migration, middleware ou interface, **atualize
`.opencode/memory/architecture.md`**. Versões e convenções ficam em
`tech-stack.md`. Se um item for decidido depois (pendência de deploy, domínio de
produção), registre em `security-rules.md` seção 11.

## Respostas

Conciso. Foque no que importa, não explique o óbvio.

</laravel-boost-guidelines>