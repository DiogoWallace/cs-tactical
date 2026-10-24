import type { NextRequest } from "next/server"

import { buildServerHeaders } from "@/lib/api/server-headers"
import { serverEnv } from "@/lib/env"
import { REMEMBER_COOKIE_PREFIX, SESSION_COOKIE } from "@/lib/session"

export type RenewedSession = {
  /** `Set-Cookie` da API, para repassar ao navegador (sessão nova, XSRF-TOKEN). */
  setCookies: string[]
  /** Header `Cookie` da requisição já com a sessão nova, para os Server Components desta página. */
  cookieHeader: string
}

/** Cookie "manter conectado" presente (o conteúdo só o Laravel valida). */
export function hasRememberCookie(request: NextRequest): boolean {
  return request.cookies.getAll().some((cookie) => cookie.name.startsWith(REMEMBER_COOKIE_PREFIX))
}

/**
 * Sessão expirada com "manter conectado": pede /api/v1/me uma vez, para o Laravel autenticar pelo
 * cookie de lembrar e emitir uma sessão nova. Ver docs/architecture/autenticacao.md#5-proteção-de-rotas-no-next.
 *
 * Só o `proxy.ts` pode fazer isso: uma chamada feita por Server Component não devolve cookies ao
 * navegador, e a página de login (que valida pela API) e o proxy (que olha o cookie de sessão)
 * discordariam para sempre, num laço /painel ↔ /login.
 *
 * Devolve null se a API recusar, não responder ou não emitir sessão: quem chamou segue para o login.
 */
export async function renewSession(request: NextRequest): Promise<RenewedSession | null> {
  const { API_INTERNAL_URL, NEXT_PUBLIC_APP_URL } = serverEnv()
  const cookieHeader = request.headers.get("cookie") ?? ""

  let response: Response
  try {
    response = await fetch(`${API_INTERNAL_URL}/api/v1/me`, {
      method: "GET",
      headers: buildServerHeaders(
        cookieHeader,
        NEXT_PUBLIC_APP_URL,
        request.headers.get("x-forwarded-for"),
      ),
      cache: "no-store",
      signal: AbortSignal.timeout(5_000),
    })
  } catch {
    return null
  }
  await response.body?.cancel()

  const setCookies = response.headers.getSetCookie()
  if (!response.ok || !setCookies.some((cookie) => cookieName(cookie) === SESSION_COOKIE)) {
    return null
  }

  return { setCookies, cookieHeader: mergeCookies(cookieHeader, setCookies) }
}

function cookieName(setCookie: string): string {
  return setCookie.slice(0, setCookie.indexOf("=")).trim()
}

/** Junta os cookies novos ao header recebido, sem decodificar valores (os do Laravel são criptografados). */
function mergeCookies(cookieHeader: string, setCookies: string[]): string {
  const jar = new Map<string, string>()
  const pairs = [...cookieHeader.split(";"), ...setCookies.map((c) => c.split(";", 1)[0] ?? "")]
  for (const pair of pairs) {
    const separator = pair.indexOf("=")
    if (separator > 0) jar.set(pair.slice(0, separator).trim(), pair.slice(separator + 1).trim())
  }
  return [...jar].map(([name, value]) => `${name}=${value}`).join("; ")
}
