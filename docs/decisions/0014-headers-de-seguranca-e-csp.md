# 0014 — Headers de segurança e Content Security Policy

- Status: Aceita
- Data: 2026-10-17

## Contexto

Até a etapa 1.7 o frontend não enviava nenhum header de segurança e anunciava `X-Powered-By:
Next.js`. A API recebia os headers padrão da imagem serversideup/php, inclusive
`Strict-Transport-Security` mesmo em HTTP, o que mistura a responsabilidade da borda de produção
(TLS) com a da aplicação.

O frontend usa Next.js 16 com Cache Components e Partial Prerendering (PPR). Segundo a
documentação do Next, CSP com nonce exige renderização dinâmica de todas as páginas e é
incompatível com PPR. Sem nonce, o Next injeta scripts inline (payload RSC e inicialização), que só
rodam com `'unsafe-inline'` em `script-src`.

## Decisão

1. **Cada camada envia os seus headers.**
   - Páginas: `next.config.ts` (`src/lib/security-headers.ts`), em todas as rotas exceto `/api/*` e
     `/sanctum/*`.
   - API: Nginx da imagem da API (`infra/docker/api/nginx/security.conf`), que substitui o arquivo
     padrão da serversideup.
   - Borda de produção: HSTS e `upgrade-insecure-requests` (bloqueio P1).
2. **Páginas**: CSP aplicada, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`,
   `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy` negando câmera,
   microfone, geolocalização, pagamento, USB e tópicos, `Cross-Origin-Opener-Policy: same-origin`,
   sem `X-Powered-By`.
3. **CSP aplicada (compatível com PPR)**: tudo restrito à própria origem (`default-src`,
   `connect-src`, `img-src`, `font-src` `'self'`), `object-src`/`frame-src`/`media-src 'none'`,
   `base-uri 'self'`, `form-action 'self'`, `frame-ancestors 'none'`. Exceções:
   `script-src 'self' 'unsafe-inline'` e `style-src 'self' 'unsafe-inline'`; `'unsafe-eval'` só em
   desenvolvimento (exigido pelo React para stacks de erro).
4. **CSP meta em Report-Only**, sem `'unsafe-inline'`. Não bloqueia; o teste E2E `csp.spec.ts`
   registra as violações. Primeira medição (etapa 1.8): 52 scripts inline (`script-src-elem`: payload
   RSC e script do `next-themes`) e 6 elementos `<style>` inline (`style-src-elem`), nenhum recurso
   externo. A CSP aplicada não teve nenhuma violação nos fluxos principais.
5. **API**: `Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none';
   form-action 'none'`, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`,
   `Referrer-Policy: no-referrer`, `Cross-Origin-Resource-Policy: same-origin`, **sem HSTS**.

## Alternativas consideradas

- **CSP com nonce**: protege contra scripts inline injetados, mas obriga a desligar o PPR e tornar
  todas as páginas dinâmicas (sem shell estático, mais carga no servidor). Fica como evolução se o
  risco de XSS crescer (conteúdo gerado por usuários na Academy/Teams).
- **SRI experimental do Next (hash)**: cobre os arquivos de script, mas não os scripts inline do
  payload RSC, que mudam a cada página. Não resolve sozinho.
- **Só Report-Only, sem CSP aplicada**: não traria proteção nenhuma. A política aplicada já bloqueia
  scripts, estilos, conexões, frames e formulários de outras origens, e o uso em iframe.
- **`unsafe-inline` + `unsafe-eval` e curingas para "funcionar sempre"**: recusada; a política não
  tem curingas nem origens externas, e um teste unitário impede que entrem.

## Consequências

- A CSP aplicada **não impede** a execução de um script inline injetado (XSS refletido ou
  armazenado). A defesa contra XSS continua sendo o React (escape por padrão), a ausência de
  `dangerouslySetInnerHTML` com dados do usuário e a validação na API. A CSP limita o impacto:
  sem carregar scripts de fora, sem enviar dados a outras origens, sem frames.
- Qualquer recurso externo novo (fonte, imagem, analytics, vídeo da Academy) exige mudar a CSP
  conscientemente, com teste.
- Antes de conteúdo gerado por usuário ser renderizado como HTML (Academy, Fase 2), reavaliar a CSP
  com nonce ou hash.
- Detalhes e procedimento de verificação: [seguranca-http.md](../architecture/seguranca-http.md).
