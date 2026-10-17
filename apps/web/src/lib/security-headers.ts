/**
 * Headers de segurança das páginas do frontend (etapa 1.8). docs/architecture/seguranca-http.md
 *
 * - /api e /sanctum ficam de fora: são respostas da API, com os headers dela (Nginx da API).
 * - HSTS e `upgrade-insecure-requests` ficam na borda de produção (TLS), não aqui.
 *
 * Duas CSPs:
 * - **Aplicada**: compatível com o Next 16 com Cache Components / Partial Prerendering. Sem nonce
 *   (incompatível com PPR, segundo a documentação do Next), os scripts inline do payload RSC
 *   exigem `'unsafe-inline'` em `script-src`. Todo o resto é restrito à própria origem.
 * - **Report-Only**: a meta, sem `'unsafe-inline'`. Não bloqueia nada; as violações aparecem no
 *   console do navegador e são registradas pelo teste E2E `csp.spec.ts`.
 */

type Header = { key: string; value: string }

function policy(directives: Record<string, string[]>): string {
  return Object.entries(directives)
    .map(([name, values]) => [name, ...values].join(" "))
    .join("; ")
}

/** Diretivas comuns às duas políticas: tudo restrito à própria origem. */
function baseDirectives(): Record<string, string[]> {
  return {
    "default-src": ["'self'"],
    "img-src": ["'self'", "data:", "blob:"],
    "font-src": ["'self'"],
    "connect-src": ["'self'"],
    "manifest-src": ["'self'"],
    "worker-src": ["'self'", "blob:"],
    "media-src": ["'none'"],
    "object-src": ["'none'"],
    "frame-src": ["'none'"],
    "base-uri": ["'self'"],
    "form-action": ["'self'"],
    "frame-ancestors": ["'none'"],
  }
}

/** CSP aplicada. Em desenvolvimento, o React precisa de `'unsafe-eval'` (stacks de erro). */
export function enforcedCsp(isDev: boolean): string {
  return policy({
    ...baseDirectives(),
    "script-src": ["'self'", "'unsafe-inline'", ...(isDev ? ["'unsafe-eval'"] : [])],
    "style-src": ["'self'", "'unsafe-inline'"],
  })
}

/** CSP meta, só observada (Report-Only): sem scripts nem estilos inline. */
export function reportOnlyCsp(isDev: boolean): string {
  return policy({
    ...baseDirectives(),
    "script-src": ["'self'", ...(isDev ? ["'unsafe-eval'"] : [])],
    "style-src": ["'self'"],
  })
}

export function securityHeaders(isDev: boolean): Header[] {
  return [
    { key: "Content-Security-Policy", value: enforcedCsp(isDev) },
    { key: "Content-Security-Policy-Report-Only", value: reportOnlyCsp(isDev) },
    { key: "X-Frame-Options", value: "DENY" },
    { key: "X-Content-Type-Options", value: "nosniff" },
    { key: "Referrer-Policy", value: "strict-origin-when-cross-origin" },
    {
      key: "Permissions-Policy",
      value:
        "camera=(), microphone=(), geolocation=(), payment=(), usb=(), browsing-topics=(), interest-cohort=()",
    },
    { key: "Cross-Origin-Opener-Policy", value: "same-origin" },
  ]
}

/** Todas as rotas, menos as encaminhadas à API (ver `rewrites` em next.config.ts). */
export const PAGE_HEADERS_SOURCE = "/((?!api/|sanctum/).*)"
