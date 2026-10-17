# Auditoria de fechamento da Fase 1 (etapa 1.8)

Branch `feat/phase-1-final-review`, 2026-10-17. Escopo aprovado: autenticação, sessões,
autorização, rotas, segurança HTTP, infraestrutura, dependências, testes, cobertura,
acessibilidade, documentos e ADRs, e preparação dos bloqueios de produção P1–P4.

## Conclusão

| | Situação |
| --- | --- |
| **Fase 1 para desenvolvimento** | **Concluída e validada.** Nenhum achado alto ou crítico em aberto que dependa de código; todos os testes passam (ver [Testes executados](#testes-executados)). |
| **Produção** | **Bloqueada** até a resolução e a verificação de P1–P4 ([bloqueios-de-producao.md](../deploy/bloqueios-de-producao.md)). G1 (proteção da `main`) deve ser resolvido antes do primeiro deploy. |

## Achados

Severidade: crítica, alta, média, baixa, informativa.

| ID | Severidade | Achado | Evidência | Situação | Ação necessária |
| --- | --- | --- | --- | --- | --- |
| F1 | **Alta** (produção) | Rate limit de login contornável com `X-Forwarded-For` forjado quando o Next.js recebe o tráfego diretamente | Sondagem A18: 6 logins inválidos trocando o cabeçalho → 422 ×6; controle A18b, mesmo IP → 429 na 6ª | **Bloqueio de produção** (P1/P2), mantido como impedimento ao deploy. Em desenvolvimento não há borda, por desenho (ADR 0009) | Borda conforme `infra/deploy/edge/nginx.conf.example`; verificar com `scripts/verify-edge.sh` |
| F2 | Média | Lista no lugar da senha atual em `PUT /me/password` e `POST /me/email` gerava erro 500 (`password_verify()` recebia array) | Sondagens A11/A12 → 500; log `TypeError ... array given` | **Corrigido**: `bail` antes de `current_password` nos dois Form Requests; 5 testes de regressão (falhavam antes, passam depois) | — |
| F3 | Média | Contraste abaixo de WCAG AA no tema claro (botões primários, texto `primary`, `success` e alertas de erro) | axe `color-contrast` (serious); cálculo: branco sobre primary 3,18:1, primary sobre fundo 3,14:1, success 3,84:1, erro sobre fundo tingido ~4,0:1 | **Corrigido**: `--primary` L 0,66→0,55, `--success` 0,58→0,52, `--destructive` 0,58→0,52 (mesmo matiz). Todos os pares ≥ 4,5:1; axe sem violações sérias nos dois temas | — |
| F4 | Média | Frontend sem nenhum header de segurança e com `X-Powered-By: Next.js` | Sondagem de headers em `/login` e `/painel` | **Corrigido** (ADR 0014): CSP, `X-Frame-Options`, `nosniff`, `Referrer-Policy`, `Permissions-Policy`, COOP; sem `X-Powered-By`. Testes unitários e E2E | — |
| F5 | Baixa | API enviava HSTS em HTTP (padrão da imagem serversideup) e `X-Frame-Options: SAMEORIGIN` | `curl -I /api/v1/health` | **Corrigido**: `infra/docker/api/nginx/security.conf` com headers de API, sem HSTS (responsabilidade da borda) | HSTS na borda (P1) |
| F6 | Baixa (risco aceito temporariamente) | `braces` 3.0.3: negação de serviço por padrões aninhados (GHSA-vfj7-8cjw-p6xm, CWE-674), sem versão corrigida | `pnpm audit`: 1 alta; `pnpm audit --prod`: nenhuma | **Aceitação temporária** (ver [Análise do `braces`](#análise-do-braces)) | Reavaliar a cada execução do Dependabot; remover a aceitação se surgir correção ou se o pacote passar a fazer parte do runtime |
| F7 | Baixa | CSP aplicada precisa de `'unsafe-inline'` em `script-src`/`style-src` | Next 16: nonce incompatível com PPR; Report-Only mede 49–52 scripts e 6 estilos inline | **Aceito e documentado** (ADR 0014); a CSP aplicada não teve violações nos fluxos | Reavaliar (nonce/hash) antes de renderizar conteúdo gerado por usuário (Academy) |
| F8 | Informativa | Avisos `Could not validate instant` em todas as rotas do dev server | Logs: `pnpm add @playwright/test` às 00:03:55 UTC moveu o `next` no `node_modules/.pnpm`; `Could not find the Next.js package` às 00:03:58; avisos de 00:04 até o restart às 00:42; não voltaram | **Causa identificada**; sem mudança de código | Reiniciar o `web` depois de mudar dependências (documentado em [wsl2.md](wsl2.md#problemas-comuns) e [dependencias.md](dependencias.md)) |
| F9 | Baixa | Teste `me → it is limited to 120 requests per minute per user` dependia do relógio real: falhava quando a execução ficava lenta | Com Xdebug (path coverage) o teste levou 74,49 s e recebeu 200 em vez de 429 na 121ª requisição | **Corrigido**: `freezeTime()` no teste; passa com Xdebug (2,98 s) e na suíte normal. Efeito de instrumentação num teste frágil, sem defeito na aplicação | — |
| F10 | Baixa | ADR 0010 e comentário de `config/fortify.php` diziam que a troca de senha usaria o Fortify | Código: `PUT /api/v1/me/password` no módulo Identity; não existe `UpdateUserPassword` | **Corrigido**: nota de implementação na ADR 0010 e comentário atualizado | — |
| F11 | Média (governança) | `main` sem branch protection nem checks obrigatórios (G1) | API do GitHub: 403 "Upgrade to GitHub Pro or make this repository public" | **Pendente de decisão** do responsável pelo repositório | Ver G1 em [bloqueios-de-producao.md](../deploy/bloqueios-de-producao.md#g1--main-sem-proteção-automática) |
| F12 | Informativa | Rotas `/me/*` não têm `EnsureFrontendSession` (as de `/auth/*` têm) | `route:list` | **Sem ação**: sem `Origin`/`Referer` do frontend não há sessão, e a resposta é 401 (sondagens A1, A8, A9) | — |

## Sondagem empírica de segurança

Executada pelo proxy da stack E2E (mesma origem, banco descartável), script mantido fora do
repositório; 24 de 25 verificações passaram, e a restante é o F1 (bloqueio de produção).

| ID | Verificação | Resultado |
| --- | --- | --- |
| A1 | `GET /me` sem cookie | 401 `{message, code}` |
| A2 | Login sem `Origin`/`Referer` do frontend | 403 `frontend_session_required` |
| A3 | Login sem `X-XSRF-TOKEN` | 419 |
| A4–A6 | Login válido; cookie de sessão `HttpOnly; SameSite=lax; Path=/`; `XSRF-TOKEN` legível pelo JS | ok |
| A7 | `/me` sem `password`/`remember_token` | ok |
| A8 | Cookie válido com `Origin`/`Referer` externos | 401 (não é sessão) |
| A9 | `Authorization: Bearer` | ignorado (401) |
| A10–A12 | Arrays em `/me/profile`, `/me/password`, `/me/email` | 422 (A11/A12 eram 500: F2) |
| A13 | Nome com 20 mil caracteres | 422 |
| A14–A15 | Mass assignment (`platform_role`, `email`, `email_verified_at`, `id`) e capacidades de `user` | ignorados; `abilities: []` |
| A16–A17 | Enumeração em "esqueci minha senha" e login | respostas idênticas |
| A18 / A18b | Rate limit com `X-Forwarded-For` trocado / controle | **F1** / 429 na 6ª |
| A19 | Cadastro com `platform_role: admin` | criado como `user`, não verificado |
| A20 | Link de verificação adulterado | 403 |
| A21–A22 | 404 e 405 da API | JSON padronizado, sem trace |
| A23 | Health | sem `checks` (`HEALTH_EXPOSE_DETAILS=false`) |
| A24 | Logout | cookie antigo passa a 401 |

Matriz de rotas (`route:list`): todas as rotas de `/api/v1/auth/*` têm `EnsureFrontendSession` e
rate limit; as de entrada de dados também `RejectAuthenticated` e `EnsureStringFields`; todas as
de `/me/*` têm `auth:sanctum` e rate limit; `email/verify` e `me/email/confirm` exigem assinatura
relativa. Público só: `/api/v1/health` e `/sanctum/csrf-cookie`, mais cadastro, login e
recuperação de senha (lista explícita da ADR 0012, coberta por teste de arquitetura).

## Sessões e configuração

- Sessão no Redis, 120 minutos, cookie `HttpOnly`, `SameSite=lax`; "manter conectado" por 30 dias
  (E2E confirma 29–30 dias). `Secure` depende de `SESSION_SECURE_COOKIE=true` em produção (P4).
- Login regenera a sessão; troca de senha mantém a atual e encerra as outras (E2E com dois
  contextos); logout invalida o cookie (A24, E2E).
- CORS sem origens externas (`allowed_origins: []`); Sanctum só com domínios stateful.
- Logs da aplicação sem dados sensíveis (só ids e nomes de verificação).
- `system:check-production-config` criado para P3/P4, com testes para cada item inseguro.

## Dependências

| Verificação | Resultado |
| --- | --- |
| `composer audit` | nenhuma vulnerabilidade |
| `pnpm audit` | 1 alta (`braces`, só desenvolvimento, F6) |
| `pnpm audit --prod` | nenhuma vulnerabilidade |
| Desatualizados com vulnerabilidade | nenhum. Atualizações disponíveis sem aviso de segurança: `laravel/framework` 13.37.0, `react`/`react-dom` 19.3.1, `geist`, `lucide-react`; majors de Pest, PHPUnit, ESLint, TypeScript e `@types/node` ficam para PRs dedicados |
| Automação | Dependabot semanal sem automerge ([dependencias.md](dependencias.md)) |

### Análise do `braces`

- **Caminho único**: `eslint-config-next → @next/eslint-plugin-next → fast-glob → micromatch →
  braces`, só em `devDependencies` (`pnpm why braces --prod` vazio).
- **Runtime**: o build de produção não importa o pacote (nenhuma referência em `.next/server`
  na stack E2E). O frontend em produção não o executa.
- **Gatilho**: padrões glob profundamente aninhados controlados por quem chama. No projeto, os
  padrões vêm da configuração do ESLint e do plugin do Next, escritos no repositório.
- **Outros caminhos analisados**:
  - *CI*: um PR malicioso poderia mudar a configuração do ESLint para travar o job de lint. Efeito:
    falha ou timeout daquele job, sem acesso a segredos (o workflow tem `permissions: contents:
    read` e não usa segredos). Mitigação: revisão obrigatória de PRs e G1.
  - *Imagem de produção futura*: se `node_modules` com dependências de desenvolvimento fosse
    copiado para a imagem, o pacote estaria presente, mas nada o carregaria. P4 exige imagem sem
    dependências de desenvolvimento.
  - *Ferramentas locais*: só o próprio desenvolvedor controla os padrões.
- **Aceitação temporária**, válida enquanto: (1) não houver versão corrigida; (2) o pacote não
  aparecer em `pnpm audit --prod`; (3) o build de produção não o importar. Revisar a cada PR do
  Dependabot e, no mais tardar, no fechamento da Fase 2.

## Falha isolada com Xdebug

**Classificação: efeito da instrumentação sobre um teste frágil.** Não é falha da aplicação nem
de isolamento.

- **Reprodução**: suíte completa com Xdebug em path coverage, sozinha no banco de testes, com
  relatório JUnit. 326 testes, 1 falha: `SessionAuthenticationTest` › `me → it is limited to 120
  requests per minute per user`, `Expected response status code [429] but received 200`
  (linha 293), em **74,49 s**.
- **Causa**: o teste faz 121 requisições e espera 429 na última. O limitador `api` conta numa
  janela de 60 s a partir da primeira requisição. Com a instrumentação, as requisições passaram de
  60 s, a janela expirou no meio e o contador recomeçou. Sem instrumentação o teste leva ~1 s.
- **Por que não é isolamento**: a falha se repete com a suíte rodando sozinha; os outros 325
  testes passam.
- **Por que não é defeito**: o limite de 120/min é respeitado; só o teste supunha que 121
  requisições sempre caberiam num minuto de relógio real (um runner de CI lento poderia falhar
  do mesmo jeito).
- **Correção**: `$this->freezeTime()` no teste. O limitador lê o tempo pelo Carbon, então com o
  relógio parado a janela não expira. O teste passa com Xdebug (2,98 s) e na suíte normal. Os
  demais testes de limite fazem de 3 a 6 requisições (~3 s mesmo com Xdebug), longe dos 60 s.
- **Primeira medição com pcov**: uma falha anterior em `SessionAuthenticationTest` (`relation
  "users" does not exist`) foi de isolamento, causada por mim: duas execuções do `pest`
  simultâneas no mesmo banco. Não se repetiu ao rodar sozinha; o cuidado está em
  [cobertura.md](cobertura.md).

## Testes executados

Verificação final, em sequência, depois de todas as alterações:

| Verificação | Resultado |
| --- | --- |
| Pest (`make test`) | **326 passaram** (1418 asserções) |
| Vitest (`make test`) | **104 passaram** (17 arquivos) |
| Concorrência do último admin (`make test-concurrency`) | **passou**; nenhum banco descartável restante. Contraprova: sem `lockForUpdate()` os dois processos rebaixam e a plataforma fica sem admin (o teste falha) |
| `system:check-production-config` | 15 testes (cada item inseguro reprova sozinho); mutação ignorando `APP_DEBUG` é detectada. Na stack E2E (não é produção) o comando falha com 7 itens, como esperado |
| Lint (`make lint`: Pint, Larastan, ESLint, Prettier, TypeScript) | sem erros |
| Build do frontend (`pnpm build`) | concluído |
| Playwright (`make e2e`, stack nova) | **32 passaram** (31 desktop, 1 mobile); banco de desenvolvimento idêntico antes e depois |
| Cobertura (`make coverage`) | API 93,8% linhas (532/567), 92,2% métodos (94/102); frontend 71,6% linhas, 56,1% funções, 66,9% branches |

### Headers em modo de produção

Na stack E2E o frontend roda `next build && next start` (`NODE_ENV=production`). Resposta real:

- `/login`: CSP aplicada **sem `'unsafe-eval'`**, CSP Report-Only, `X-Frame-Options: DENY`,
  `nosniff`, `strict-origin-when-cross-origin`, `Permissions-Policy`, COOP `same-origin`; sem HSTS e
  sem `X-Powered-By`.
- `/api/v1/health` pelo proxy: CSP `default-src 'none'; ...`, `DENY`, `nosniff`, `no-referrer`,
  CORP `same-origin`; sem HSTS.
- Build de produção sem nenhuma referência a `braces`, `micromatch` ou `fast-glob` em
  `.next/server` e `.next/static` (0 arquivos).

## Documentos e ADRs

- ADRs 0001–0009, 0011 e 0012 conferem com o código. 0007 e 0008 descrevem fases futuras.
- ADR 0010: nota de implementação (F10).
- Novas: [ADR 0013](../decisions/0013-testes-e2e-em-stack-isolada.md) (E2E em stack isolada) e
  [ADR 0014](../decisions/0014-headers-de-seguranca-e-csp.md) (headers e CSP).
- Novos documentos: [seguranca-http.md](../architecture/seguranca-http.md),
  [cobertura.md](cobertura.md), [dependencias.md](dependencias.md); atualizados:
  [bloqueios-de-producao.md](../deploy/bloqueios-de-producao.md), [e2e.md](e2e.md),
  [wsl2.md](wsl2.md).
