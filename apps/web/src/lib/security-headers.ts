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

/** Player sem cookies do YouTube: o único frame permitido, só nas páginas de aula (ADR 0019). */
export const YOUTUBE_FRAME_ORIGIN = "https://www.youtube-nocookie.com"

type CspOptions = {
  /** Páginas de aula da Academy: `frame-src` aceita o player do YouTube. Padrão: `'none'`. */
  youtubeFrames?: boolean
}

/** Diretivas comuns às duas políticas: tudo restrito à própria origem. */
function baseDirectives({ youtubeFrames = false }: CspOptions = {}): Record<string, string[]> {
  return {
    "default-src": ["'self'"],
    "img-src": ["'self'", "data:", "blob:"],
    "font-src": ["'self'"],
    "connect-src": ["'self'"],
    "manifest-src": ["'self'"],
    "worker-src": ["'self'", "blob:"],
    "media-src": ["'none'"],
    "object-src": ["'none'"],
    "frame-src": youtubeFrames ? [YOUTUBE_FRAME_ORIGIN] : ["'none'"],
    "base-uri": ["'self'"],
    "form-action": ["'self'"],
    "frame-ancestors": ["'none'"],
  }
}

/** CSP aplicada. Em desenvolvimento, o React precisa de `'unsafe-eval'` (stacks de erro). */
export function enforcedCsp(isDev: boolean, options: CspOptions = {}): string {
  return policy({
    ...baseDirectives(options),
    "script-src": ["'self'", "'unsafe-inline'", ...(isDev ? ["'unsafe-eval'"] : [])],
    "style-src": ["'self'", "'unsafe-inline'"],
  })
}

/** CSP meta, só observada (Report-Only): sem scripts nem estilos inline. */
export function reportOnlyCsp(isDev: boolean, options: CspOptions = {}): string {
  return policy({
    ...baseDirectives(options),
    "script-src": ["'self'", ...(isDev ? ["'unsafe-eval'"] : [])],
    "style-src": ["'self'"],
  })
}

/** As duas CSPs das páginas de aula: iguais às demais, com o player do YouTube em `frame-src`. */
export function lessonCspHeaders(isDev: boolean): Header[] {
  return [
    { key: "Content-Security-Policy", value: enforcedCsp(isDev, { youtubeFrames: true }) },
    {
      key: "Content-Security-Policy-Report-Only",
      value: reportOnlyCsp(isDev, { youtubeFrames: true }),
    },
  ]
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

/**
 * Páginas de aula da Academy (`/academy/cursos/{curso}/aulas/{aula}`). Vem depois de
 * PAGE_HEADERS_SOURCE em `headers()`: para o mesmo header, a última regra que casa vale.
 */
export const LESSON_HEADERS_SOURCE = "/academy/cursos/:course/aulas/:lesson"
