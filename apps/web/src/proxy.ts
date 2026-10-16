import { NextResponse, type NextRequest } from "next/server"

import { isProtectedPath, loginUrl } from "@/lib/routes"
import { PATHNAME_HEADER, SESSION_COOKIE } from "@/lib/session"

/**
 * Proxy do Next.js (antigo middleware). Ver docs/architecture/autenticacao.md#5-proteção-de-rotas-no-next.
 *
 * - Na área autenticada, sem o cookie de sessão, redireciona ao login antes de renderizar.
 * - Não valida a sessão: o cookie pode existir e estar expirado. Quem decide é o Laravel; o
 *   layout da área logada chama /api/v1/me e redireciona ao login em 401.
 * - Repassa o caminho atual em `x-pathname`, para que esse redirecionamento volte à página certa.
 *   O valor é sobrescrito aqui (um cliente não o escolhe) e só é usado depois de `safeInternalPath`.
 */
export function proxy(request: NextRequest) {
  const { pathname, search } = request.nextUrl
  const current = `${pathname}${search}`

  if (isProtectedPath(pathname) && !request.cookies.has(SESSION_COOKIE)) {
    return NextResponse.redirect(new URL(loginUrl(current), request.url))
  }

  const headers = new Headers(request.headers)
  headers.set(PATHNAME_HEADER, current)

  return NextResponse.next({ request: { headers } })
}

export const config = {
  // Valores constantes (analisados no build). Mantenha alinhado com PROTECTED_PATH_PREFIXES.
  matcher: ["/painel", "/painel/:path*"],
}
