# Autenticação entre Next.js e Laravel

> Decisão: [ADR 0003](../decisions/0003-autenticacao-sanctum-sessao-mesma-origem.md).
> Fase 0: o proxy de mesma origem e a passagem de cookies são **validados** (etapa 0.6). Fase 1: login, cadastro e sessão são **implementados**.

## Princípios

1. O navegador fala com uma única origem (o Next.js). Não existe CORS.
2. A credencial é um cookie de sessão **HTTP-only**, emitido pelo Laravel. Nenhum token fica acessível a JavaScript ou no `localStorage`.
3. Toda requisição que altera estado exige token CSRF.
4. A autorização acontece sempre no Laravel. O Next só decide navegação (redirecionar para o login).
5. Uma única estratégia (sessão Sanctum). Tokens pessoais ficam desligados.

## Topologia

| Ambiente | Navegador acessa | Encaminhamento |
|---|---|---|
| Desenvolvimento | `http://localhost:3000` | `rewrites` do Next: `/api/*` e `/sanctum/*` → `http://api:8080` (rede Docker) |
| Produção | `https://app.<dominio>` | rewrite do Next ou proxy reverso: `/api/*` e `/sanctum/*` → serviço da API, na rede privada |

O caminho `/api` do Next fica reservado para a API do Laravel. O frontend não cria Route Handlers sob `/api`.

## Cookies

| Cookie | Emitido por | HttpOnly | Lido por JS | Função |
|---|---|---|---|---|
| `cs_tactical_session` | Laravel | Sim | Não | Identifica a sessão (dados no Redis) |
| `XSRF-TOKEN` | Laravel | Não | Sim | Valor criptografado do token CSRF, reenviado no header `X-XSRF-TOKEN` |

Atributos: `SameSite=Lax`, `Path=/`, sem `Domain` (cookie *host-only* da origem do frontend), `Secure` em produção.

## Configuração do Laravel (Fase 1)

```dotenv
SESSION_DRIVER=redis
SESSION_COOKIE=cs_tactical_session
SESSION_DOMAIN=null
SESSION_SAME_SITE=lax
SESSION_SECURE_COOKIE=false        # true em produção
SESSION_LIFETIME=120
SANCTUM_STATEFUL_DOMAINS=localhost:3000
```

- `bootstrap/app.php`: `$middleware->statefulApi()` ativa o middleware que trata como "stateful" as requisições cujo `Origin` ou `Referer` pertence a `SANCTUM_STATEFUL_DOMAINS`. Essas requisições recebem sessão, cookies criptografados e verificação CSRF.
- Rotas protegidas usam `auth:sanctum`.
- Login regenera o ID da sessão (contra *session fixation*); logout invalida a sessão e regenera o token CSRF.
- `config/cors.php` sem origens permitidas.

## Fluxos

### 1. Obter o token CSRF

```
Navegador ── GET /sanctum/csrf-cookie ──► Next (rewrite) ──► Laravel
Navegador ◄── 204 + Set-Cookie: XSRF-TOKEN, cs_tactical_session ──┘
```

O `apiClient` faz essa chamada uma vez, antes da primeira requisição de escrita (ou novamente após receber `419`).

### 2. Login (Client Component)

```
POST /api/v1/auth/login
Headers: X-XSRF-TOKEN: <valor do cookie XSRF-TOKEN, decodificado de URL>
         Accept: application/json
Body:    { email, password, remember }
```

O Laravel valida as credenciais (Fortify), regenera a sessão e devolve um novo `Set-Cookie` de sessão. O navegador guarda o cookie automaticamente porque a resposta veio da mesma origem.

### 3. Requisições do navegador (Client Components)

`apiClient` (em `lib/api/client.ts`):

- usa caminhos relativos (`/api/v1/...`) e `credentials: 'same-origin'`;
- em métodos de escrita, lê `XSRF-TOKEN` de `document.cookie` e envia `X-XSRF-TOKEN`;
- envia `Accept: application/json`;
- trata `401` (sessão expirada → redireciona ao login), `403`, `404`, `419` (renova o CSRF e repete uma vez) e `422` (erros de validação para o formulário).

### 4. Requisições do servidor (Server Components)

No servidor não há navegador, então o cookie precisa ser repassado manualmente. `serverFetch` (em `lib/api/server.ts`, com `import 'server-only'`):

- chama o Laravel direto pela rede interna (`API_INTERNAL_URL=http://api:8080`);
- copia o header `Cookie` da requisição atual (via `cookies()` do Next);
- envia `Origin` e `Referer` com a URL pública do frontend, para o Sanctum tratar a chamada como *stateful*;
- usa `cache: 'no-store'` para dados de usuário (nunca cachear respostas autenticadas entre usuários).

**Regra:** Server Components **só leem**. Respostas do Laravel a chamadas feitas no servidor podem trazer `Set-Cookie` (renovação da sessão), e esses cookies não chegam ao navegador automaticamente. Por isso escritas acontecem no navegador via `apiClient`, passando pelo proxy. Se no futuro usarmos Server Actions para escrita, elas deverão repassar `X-XSRF-TOKEN` e copiar os `Set-Cookie` da resposta para o navegador, e isso será documentado numa ADR.

### 5. Proteção de rotas no Next

`proxy.ts` (antigo `middleware.ts` no Next 16) verifica apenas se o cookie de sessão **existe**, para redirecionar visitantes ao login antes de renderizar. Não valida a sessão: quem decide é o Laravel, e páginas protegidas tratam `401` vindo do `serverFetch` redirecionando ao login.

### 6. Logout

`POST /api/v1/auth/logout` (com CSRF) → Laravel invalida a sessão no Redis e expira o cookie.

## Autorização

- `auth:sanctum` garante quem é o usuário; Policies decidem o que ele pode fazer.
- Recursos de equipe usam rotas aninhadas com `scopeBindings()` (`/teams/{team}/strategies/{strategy}` devolve 404 se a estratégia for de outra equipe).
- Papéis: ver [ADR 0007](../decisions/0007-papeis-de-equipe-e-funcoes-no-jogo.md).

## Rate limiting

| Rota | Limite inicial | Chave |
|---|---|---|
| login, recuperação de senha | 5/min | e-mail + IP |
| cadastro | 3/min | IP |
| API autenticada | 120/min | ID do usuário |

Os contadores ficam no Redis.

## Riscos e como são cobertos

| Risco | Mitigação |
|---|---|
| CSRF | Cookie `SameSite=Lax` + token `X-XSRF-TOKEN` obrigatório em escritas (419 sem ele) |
| XSS roubando sessão | Cookie de sessão HTTP-only; nenhum token em JS |
| Session fixation | Regeneração de sessão no login |
| Cache de dados de um usuário servido a outro | `serverFetch` com `no-store`; rotas autenticadas dinâmicas |
| `Origin` ausente em chamadas servidor→API | `serverFetch` sempre envia `Origin`/`Referer` |
| IDOR entre equipes | Rotas aninhadas, scoped bindings, Policies e testes de acesso negado |

## Plano de validação

**Fase 0 (etapa 0.6)**, sem login real:

- [ ] `GET /sanctum/csrf-cookie` através do Next (porta 3000) devolve `Set-Cookie` de `XSRF-TOKEN` e da sessão, com os atributos esperados.
- [ ] Um Server Component consegue chamar a API pela rede interna (`/api/v1/health`).

**Fase 1**, testes automatizados:

- [ ] Login com credenciais válidas define o cookie de sessão e `GET /api/v1/me` responde 200.
- [ ] `POST` sem `X-XSRF-TOKEN` responde 419.
- [ ] Requisição sem cookie responde 401.
- [ ] Requisição com `Origin` fora de `SANCTUM_STATEFUL_DOMAINS` não é tratada como sessão.
- [ ] Server Component com cookie repassado recebe o usuário; sem cookie, redireciona ao login.
- [ ] Logout invalida a sessão (o cookie antigo passa a receber 401).
- [ ] Rate limit de login responde 429 após o limite.
- [ ] Teste E2E (Playwright): cadastro → login → página protegida → logout.
