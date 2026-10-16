# Autenticação entre Next.js e Laravel

> Decisões: [ADR 0003](../decisions/0003-autenticacao-sanctum-sessao-mesma-origem.md) (sessão e mesma origem), [ADR 0009](../decisions/0009-ip-real-do-cliente-e-proxies-confiaveis.md) (IP real do cliente), [ADR 0010](../decisions/0010-fortify-headless-em-api-v1-auth.md) (Fortify).
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
| Desenvolvimento | `http://localhost:3000` | `rewrites` (`beforeFiles`) do Next: `/api/*` e `/sanctum/*` → `http://api:8080` (rede Docker), em `apps/web/next.config.ts` |
| Produção | `https://app.<dominio>` | rewrite do Next ou proxy reverso: `/api/*` e `/sanctum/*` → serviço da API, na rede privada |

O caminho `/api` do Next fica reservado para a API do Laravel. O frontend não cria Route Handlers sob `/api` (os rewrites usam `beforeFiles`, então teriam prioridade de qualquer forma).

O destino dos rewrites é lido de `API_INTERNAL_URL` quando o Next inicia (`next dev`) ou no build (`next build`). Em produção, a variável precisa estar definida no ambiente do build.

## Cookies

| Cookie | Emitido por | HttpOnly | Lido por JS | Função |
|---|---|---|---|---|
| `cs_tactical_session` | Laravel | Sim | Não | Identifica a sessão (dados no Redis) |
| `XSRF-TOKEN` | Laravel | Não | Sim | Valor criptografado do token CSRF, reenviado no header `X-XSRF-TOKEN` |

Atributos: `SameSite=Lax`, `Path=/`, sem `Domain` (cookie *host-only* da origem do frontend), `Secure` em produção.

## Configuração do Laravel (Fase 0 e etapa 1.0)

```dotenv
SESSION_DRIVER=redis
SESSION_COOKIE=cs_tactical_session
SESSION_DOMAIN=null
SESSION_SAME_SITE=lax
SESSION_SECURE_COOKIE=false        # true em produção
SESSION_LIFETIME=120
SANCTUM_STATEFUL_DOMAINS=localhost:3000,127.0.0.1:3000
TRUSTED_PROXIES=172.31.250.10      # definido pelo compose.yaml: IP fixo do container web
```

- `SANCTUM_STATEFUL_DOMAINS` lista só as origens do frontend. O padrão do Sanctum, que incluiria a própria API (`127.0.0.1:8000`), não é usado: o navegador nunca chama a API diretamente.

- `bootstrap/app.php`: `$middleware->statefulApi()` ativa o middleware que trata como "stateful" as requisições cujo `Origin` ou `Referer` pertence a `SANCTUM_STATEFUL_DOMAINS`. Essas requisições recebem sessão, cookies criptografados e verificação CSRF.
- Rotas protegidas usam `auth:sanctum`.
- Login regenera o ID da sessão (contra *session fixation*); logout invalida a sessão e regenera o token CSRF.
- `config/cors.php` sem origens permitidas.
- `/api/v1/health` não usa sessão (`withoutMiddleware(EnsureFrontendRequestsAreStateful)`): ele precisa responder mesmo com o Redis, onde ficam as sessões, fora do ar.
- A tabela `personal_access_tokens` não é criada: tokens pessoais estão desligados.

## IP real do cliente

Detalhes e alternativas na [ADR 0009](../decisions/0009-ip-real-do-cliente-e-proxies-confiaveis.md).

```
Desenvolvimento:  navegador ──► web (Next, IP fixo) ──► api (Laravel: confia só no IP do web)

Produção:         cliente ──► borda (TLS; substitui X-Forwarded-For pelo IP da conexão;
                                     remove Forwarded, X-Real-IP, X-Forwarded-Host...)
                               ├── /api/*, /sanctum/* ──► api (Laravel: confia só na borda)
                               └── demais rotas ───────► web (Next)
```

- O Next.js **não** acrescenta o IP da conexão ao `X-Forwarded-For`: o rewrite de `/api/*` repassa o cabeçalho do cliente sem alteração.
- O Laravel (`config/trustedproxy.php`, `bootstrap/app.php`) lê `X-Forwarded-For` e `X-Forwarded-Proto` **apenas** quando a conexão vem do container `web`. Vindo de qualquer outro endereço, inclusive do gateway do Docker (porta 8000 do host), os cabeçalhos são ignorados.
- O IP do cliente é a entrada mais à direita que não é um proxy confiável.
- `X-Forwarded-Host` nunca é confiável.
- Em produção, a borda é obrigatória, é o único ponto de entrada público e é a **única** responsável por estabelecer o IP real: ela substitui o `X-Forwarded-For` recebido e remove os demais cabeçalhos de IP e host vindos do cliente. A topologia recomendada encaminha `/api/*` direto da borda para a API, para que o IP não dependa do Next.js. Itens obrigatórios antes do deploy: [bloqueios de produção](../deploy/bloqueios-de-producao.md) P1 a P3.
- Em desenvolvimento, sem borda, o IP é o que o cliente declarar ao Next.js (ou o do container `web`). Isso só é aceitável localmente.

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

O Laravel valida as credenciais (Fortify, ver [ADR 0010](../decisions/0010-fortify-headless-em-api-v1-auth.md)), regenera a sessão e devolve um novo `Set-Cookie` de sessão. Credenciais inválidas respondem `422` (nunca `401`, que significa "sem sessão"). O navegador guarda o cookie automaticamente porque a resposta veio da mesma origem.

### 3. Requisições do navegador (Client Components)

`apiClient` (em `lib/api/client.ts`):

- usa caminhos relativos (`/api/v1/...`) e `credentials: 'same-origin'`;
- em métodos de escrita, lê `XSRF-TOKEN` de `document.cookie`, aplica `decodeURIComponent` (o navegador guarda o valor codificado) e envia `X-XSRF-TOKEN`;
- envia `Accept: application/json`;
- trata `419` (renova o CSRF e repete uma vez) e `422` (erros de validação para o formulário, em `ApiError.validationErrors`);
- em `401` (sessão inexistente ou expirada), redireciona para `/login?next=<página atual>` e rejeita com `ApiError`. Quem espera um 401 (ex.: verificar se há sessão) passa `redirectOnUnauthenticated: false`. O `next` só aceita caminhos internos (`safeInternalPath` em `lib/routes.ts`, contra open redirect);
- demais erros chegam como `ApiError`, com `status` e `code` estável ([convenções](../api/convencoes.md#erros)).

### 4. Requisições do servidor (Server Components)

> **Next.js 16 com Cache Components:** ler `cookies()` torna o componente dependente da requisição. Ele não pode ser pré-renderizado e **precisa ficar dentro de `<Suspense>`** (caso contrário o build falha). O padrão é: a página renderiza a parte estática e o trecho que chama `serverFetch` fica num componente envolvido por `<Suspense fallback={...}>`. Exemplo: `apps/web/src/app/status/page.tsx`.

No servidor não há navegador, então o cookie precisa ser repassado manualmente. `serverFetch` (em `lib/api/server.ts`, com `import 'server-only'`):

- chama o Laravel direto pela rede interna (`API_INTERNAL_URL=http://api:8080`);
- copia o header `Cookie` da requisição atual (via `cookies()` do Next);
- envia `Origin` e `Referer` com a URL pública do frontend, para o Sanctum tratar a chamada como *stateful*;
- repassa o `X-Forwarded-For` recebido, como o rewrite de `/api/*` faz;
- usa `cache: 'no-store'` para dados de usuário (nunca cachear respostas autenticadas entre usuários);
- em `401`, chama `redirect('/login')` (fora de `try/catch`, porque o redirect do Next funciona lançando uma exceção). Com `redirectOnUnauthenticated: false`, lança `ApiError`.

**Regra:** Server Components **só leem**. Respostas do Laravel a chamadas feitas no servidor podem trazer `Set-Cookie` (renovação da sessão), e esses cookies não chegam ao navegador automaticamente. Por isso escritas acontecem no navegador via `apiClient`, passando pelo proxy. Se no futuro usarmos Server Actions para escrita, elas deverão repassar `X-XSRF-TOKEN` e copiar os `Set-Cookie` da resposta para o navegador, e isso será documentado numa ADR.

### 5. Proteção de rotas no Next

Implementado na etapa 1.3. Grupos de rotas em `apps/web/src/app`:

| Grupo | Rotas | Acesso |
|---|---|---|
| `(marketing)` | `/`, `/status` | público |
| `(auth)` | `/login`, `/cadastro` | público; quem já tem sessão válida vai ao painel |
| `(app)` | `/painel` | autenticado |

Camadas de proteção da área `(app)`:

1. **`src/proxy.ts`** (antigo `middleware.ts`): roda só nos caminhos de `PROTECTED_PATH_PREFIXES` (`lib/routes.ts`; o `matcher` repete a lista, porque precisa ser constante). Sem o cookie `cs_tactical_session`, redireciona com `307` para `/login?next=<caminho>`. Não valida a sessão: o cookie pode existir e estar expirado. Ele também grava o caminho atual no header `x-pathname`, sobrescrevendo qualquer valor enviado pelo cliente.
2. **Layout e páginas da área logada** leem o usuário com `getCurrentUser()` (`features/identity/api`, `GET /api/v1/me` via `serverFetch`, com `cache()` do React para uma chamada por requisição). Em `401`, o `serverFetch` redireciona para `/login?next=<x-pathname>`. É isso que trata o cookie expirado.
3. **A autorização de cada dado é sempre da API.**

Nas páginas de login e cadastro, `RedirectIfAuthenticated` consulta `/me` (não o cookie) e redireciona ao painel quem tem sessão válida. Usar o cookie criaria um laço login ↔ painel com uma sessão expirada, porque o Laravel mantém um cookie de sessão também para visitantes.

O `next` passa sempre por `safeInternalPath` (`afterLoginPath` no login): URLs externas, `//host`, `/\host` e caracteres de controle levam ao painel.

**Depois de entrar, criar conta ou sair**, o frontend faz uma navegação completa (`window.location.assign`, em `features/identity/lib/navigation.ts`), não `router.replace` + `router.refresh()`. A sessão mudou, então nenhum estado do cliente deve sobreviver. Na validação da etapa 1.3, a navegação pelo router deixava a área logada presa nos esqueletos de carregamento.

### 6. Logout

`POST /api/v1/auth/logout` (com CSRF) → Laravel invalida a sessão no Redis e expira o cookie.

### 7. Verificação de e-mail e redefinição de senha (etapa 1.4)

Detalhes na [ADR 0010](../decisions/0010-fortify-headless-em-api-v1-auth.md#links-enviados-por-e-mail).

```
Cadastro ─► evento Registered ─► fila ─► e-mail com FRONTEND_URL/verificar-email/{id}/{hash}?expires&signature
Navegador abre o link ─► página do Next ─► GET /api/v1/auth/email/verify/{id}/{hash}?expires&signature (mesma query)
                                           └─ sessão + assinatura relativa + id/hash do usuário logado ─► 204

/esqueci-senha ─► POST forgot-password ─► 200 sempre ─► fila ─► e-mail com FRONTEND_URL/redefinir-senha#token&email
/redefinir-senha lê o fragmento ─► POST reset-password ─► 200 ─► login com a nova senha
```

- As páginas desses links usam `Referrer-Policy: same-origin`: o Sanctum reconhece a sessão pelo `Referer` nas chamadas GET à própria API, e nada das URLs vai para outros sites.
- Depois de redefinir a senha, as outras sessões caem na próxima requisição (`AuthenticateSession` do Sanctum) e o `remember_token` é trocado.

## Autorização

- `auth:sanctum` garante quem é o usuário; Policies decidem o que ele pode fazer.
- Recursos de equipe usam rotas aninhadas com `scopeBindings()` (`/teams/{team}/strategies/{strategy}` devolve 404 se a estratégia for de outra equipe).
- Papéis: ver [ADR 0007](../decisions/0007-papeis-de-equipe-e-funcoes-no-jogo.md).

## Rate limiting

| Rota | Limite inicial | Chave |
|---|---|---|
| login, recuperação de senha | 5/min | e-mail + IP real ([ADR 0009](../decisions/0009-ip-real-do-cliente-e-proxies-confiaveis.md)) |
| cadastro | 3/min | IP |
| API autenticada (`/me`, logout) | 120/min | ID do usuário |

Os contadores ficam no Redis.

## Riscos e como são cobertos

| Risco | Mitigação |
|---|---|
| CSRF | Cookie `SameSite=Lax` + token `X-XSRF-TOKEN` obrigatório em escritas (419 sem ele) |
| XSS roubando sessão | Cookie de sessão HTTP-only; nenhum token em JS |
| Session fixation | Regeneração de sessão no login |
| Cache de dados de um usuário servido a outro | `serverFetch` com `no-store`; rotas autenticadas dinâmicas |
| `Origin` ausente em chamadas servidor→API | `serverFetch` sempre envia `Origin`/`Referer` |
| IP falsificado via `X-Forwarded-For` para escapar do rate limiting | Só o IP fixo do container `web` é confiável; entrada mais à direita; borda obrigatória em produção (ADR 0009) |
| Open redirect pelo parâmetro `next` do login | `safeInternalPath` aceita só caminhos internos |
| IDOR entre equipes | Rotas aninhadas, scoped bindings, Policies e testes de acesso negado |

## Plano de validação

**Fase 0 (etapa 0.6)**, sem login real. Concluído:

- [x] `GET /sanctum/csrf-cookie` através do Next (porta 3000) devolve `Set-Cookie` de `XSRF-TOKEN` (`samesite=lax`, legível por JS) e de `cs_tactical_session` (`httponly; samesite=lax`). Verificado com `curl` e num navegador real (Playwright): o cookie de sessão não aparece em `document.cookie`.
- [x] Escrita vinda do frontend **sem** `X-XSRF-TOKEN` responde 419 (Pest, com verificação CSRF real).
- [x] Escrita com cookie + `X-XSRF-TOKEN` válidos responde 200 (Pest).
- [x] Token CSRF de outra sessão responde 419 (Pest).
- [x] Origem fora de `SANCTUM_STATEFUL_DOMAINS` não recebe sessão (Pest).
- [x] Preflight CORS de outra origem não recebe `Access-Control-Allow-Origin` (Pest e `curl`).
- [x] Server Component chama a API pela rede interna, repassando cookies e `Origin` (página `/status`).
- [x] Client Component chama a API pelo proxy e recebe o cookie CSRF (botão "Testar pelo navegador" em `/status`).
- [x] `apiClient`: envia o token decodificado, busca o cookie antes da primeira escrita e repete uma vez após 419 (Vitest).

> Nota para os testes do Laravel: com `APP_ENV=testing` o framework **desliga** a verificação CSRF. Os testes de CSRF em `tests/Feature/Auth/SanctumSpaFoundationTest.php` mudam o ambiente para `local` e usam sessões no Redis para reproduzir o comportamento real. Requisições JSON de teste só enviam cookies com `withCredentials()`.

**Etapa 1.0**, concluído:

- [x] `X-Forwarded-For` só é lido quando a conexão vem do container `web`; direto na API ou pelo gateway do Docker é ignorado (Pest).
- [x] Entradas prefixadas pelo cliente são ignoradas; valor malformado nunca vira o IP (Pest).
- [x] Trocar o cabeçalho falsificado não cria um novo contador de rate limiting (Pest).
- [x] `X-Forwarded-Host` nunca é confiável; `X-Forwarded-Proto` só vindo do proxy (Pest).
- [x] `Forwarded`, `X-Real-IP` e similares são ignorados mesmo vindos do proxy; topologias de produção e modos de falha (Pest).
- [x] `127.0.0.1:3000` é origem stateful; a própria API (`127.0.0.1:8000`) não é (Pest).
- [x] `apiClient` redireciona ao login em 401 (exceto com `redirectOnUnauthenticated: false`); `serverFetch` idem (Vitest).

**Fase 1**, testes automatizados:

- [x] Login com credenciais válidas define o cookie de sessão e `GET /api/v1/me` responde 200 (Pest e `curl` pelo proxy, etapa 1.2).
- [x] `POST` sem `X-XSRF-TOKEN` responde 419 (login e logout, Pest e `curl`).
- [x] Requisição sem cookie responde 401.
- [x] Requisição com `Origin` fora de `SANCTUM_STATEFUL_DOMAINS` não é tratada como sessão, mesmo com um cookie de sessão válido.
- [x] Server Component com cookie repassado recebe o usuário; sem cookie, redireciona ao login (etapa 1.3: Vitest e navegador).
- [x] Logout invalida a sessão (o cookie antigo passa a receber 401) e troca o token CSRF.
- [x] Rate limit de login responde 429 após o limite.
- [x] Login regenera o ID da sessão; senha errada e e-mail inexistente têm a mesma resposta; `Bearer` é ignorado.
- [ ] Teste E2E (Playwright): cadastro → login → página protegida → logout.
