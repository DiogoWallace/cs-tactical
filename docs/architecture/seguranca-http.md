# Segurança HTTP: headers e Content Security Policy

Decisão: [ADR 0014](../decisions/0014-headers-de-seguranca-e-csp.md). Este documento descreve o
estado atual, quem envia cada header e como verificar.

## Responsabilidades

| Camada | Onde | Envia |
| --- | --- | --- |
| Borda de produção | `infra/deploy/edge/nginx.conf.example` (modelo, bloqueio P1) | TLS, `Strict-Transport-Security`, redirecionamento HTTP → HTTPS |
| Frontend (páginas) | `apps/web/src/lib/security-headers.ts`, via `headers()` do `next.config.ts` | CSP, CSP Report-Only, `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, `Cross-Origin-Opener-Policy`; `poweredByHeader: false` |
| API | `infra/docker/api/nginx/security.conf` (substitui o arquivo da imagem serversideup) | CSP de API, `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Cross-Origin-Resource-Policy`; **sem HSTS** |

Os headers das páginas não se aplicam a `/api/*` e `/sanctum/*`, que são respostas da API passando
pelo proxy do Next (`PAGE_HEADERS_SOURCE`); assim nenhum header sai duplicado.

## Valores

### Páginas

| Header | Valor |
| --- | --- |
| `Content-Security-Policy` | `default-src 'self'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; manifest-src 'self'; worker-src 'self' blob:; media-src 'none'; object-src 'none'; frame-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'` (+ `'unsafe-eval'` em `script-src` só em desenvolvimento) |
| `Content-Security-Policy-Report-Only` | as mesmas diretivas, com `script-src 'self'` e `style-src 'self'` (sem `'unsafe-inline'`) |
| `X-Frame-Options` | `DENY` |
| `X-Content-Type-Options` | `nosniff` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` (as páginas de link assinado usam `same-origin` por metadado) |
| `Permissions-Policy` | `camera=(), microphone=(), geolocation=(), payment=(), usb=(), browsing-topics=(), interest-cohort=()` |
| `Cross-Origin-Opener-Policy` | `same-origin` |

### API

| Header | Valor |
| --- | --- |
| `Content-Security-Policy` | `default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'` |
| `X-Frame-Options` | `DENY` |
| `X-Content-Type-Options` | `nosniff` |
| `Referrer-Policy` | `no-referrer` |
| `Cross-Origin-Resource-Policy` | `same-origin` |

## CSP: por que `'unsafe-inline'` em `script-src`

O frontend usa Cache Components / Partial Prerendering. A documentação do Next 16 informa que CSP
com nonce exige renderização dinâmica de todas as páginas e é incompatível com PPR. Sem nonce, o
Next injeta scripts inline (payload RSC e inicialização), que só rodam com `'unsafe-inline'`.

A política aplicada continua bloqueando: scripts e estilos de outras origens, `fetch`/XHR/WebSocket
para outras origens, plugins (`object`), frames (em qualquer direção), `<base>` e envio de
formulários para outras origens. Ela **não** bloqueia um script inline injetado; a defesa contra
XSS é o React (escape por padrão; nenhum `dangerouslySetInnerHTML` no código) e a validação na API.

### Medição da CSP Report-Only (meta)

Registrada pelo E2E `csp.spec.ts` (home, cadastro, login, painel, conta e troca de tema), com o
frontend em build de produção:

| Diretiva | Violações | Origem |
| --- | --- | --- |
| `script-src-elem` | 49 a 52 (varia com o conteúdo da página) | scripts inline do Next (payload RSC, inicialização) e do `next-themes` |
| `style-src-elem` | 6 | elementos `<style>` inseridos por chunks JS |

Nenhuma violação por recurso externo. A **CSP aplicada não teve nenhuma violação** nesses fluxos
(o teste falha se tiver).

Para aplicar a política meta seria preciso CSP com nonce (desligando o PPR) ou que o Next passe a
suportar hash/nonce com PPR. Reavaliar antes de renderizar conteúdo gerado por usuário (Academy).

## Verificação

- Unitário: `apps/web/src/lib/security-headers.test.ts` (sem curingas nem origens externas,
  `'unsafe-eval'` só em desenvolvimento, sem HSTS, exclusão de `/api` e `/sanctum`).
- E2E (build de produção do Next + Nginx da API): `security-headers.spec.ts` e `csp.spec.ts`.
- Manual:

  ```bash
  curl -sI http://localhost:3000/login
  ```

  ```bash
  curl -sI http://localhost:8000/api/v1/health
  ```

- Produção: `scripts/verify-edge.sh https://<domínio>` confere HSTS, CSP, `X-Powered-By`,
  redirecionamento HTTPS e cookies `Secure` (bloqueios P1 e P4).
