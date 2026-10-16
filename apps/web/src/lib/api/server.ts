import "server-only"

import { cookies, headers } from "next/headers"
import { redirect } from "next/navigation"

import { serverEnv } from "@/lib/env"
import { LOGIN_PATH } from "@/lib/routes"

import { ApiError, ApiUnavailableError, parseBody } from "./errors"

/**
 * Chamadas à API feitas no servidor (Server Components). Ver docs/architecture/autenticacao.md.
 *
 * - Vai direto para a API pela rede interna, sem passar pelo proxy.
 * - Repassa os cookies da requisição do usuário, para que o Laravel reconheça a sessão.
 * - Envia Origin/Referer do frontend, para o Sanctum tratar a chamada como "stateful".
 * - Repassa o X-Forwarded-For recebido, como faz o proxy de /api (ADR 0009).
 * - Nunca usa cache: respostas autenticadas não podem ser compartilhadas entre usuários.
 * - Um 401 redireciona ao login, a menos que `redirectOnUnauthenticated: false`.
 *
 * Somente leitura (GET). Escritas acontecem no navegador via `apiClient`, porque cookies
 * renovados pela API numa chamada feita no servidor não chegariam ao navegador.
 *
 * Com Cache Components, quem chama esta função precisa estar dentro de <Suspense>.
 */
type ServerFetchOptions = {
  /** Padrão: true. Com false, o 401 é lançado como ApiError (ex.: páginas públicas que mostram o usuário, se houver). */
  redirectOnUnauthenticated?: boolean
}

export async function serverFetch<T>(
  path: `/${string}`,
  { redirectOnUnauthenticated = true }: ServerFetchOptions = {},
): Promise<T> {
  const { API_INTERNAL_URL, NEXT_PUBLIC_APP_URL } = serverEnv()
  const cookieHeader = (await cookies()).toString()
  const forwardedFor = (await headers()).get("x-forwarded-for")

  let response: Response
  try {
    response = await fetch(`${API_INTERNAL_URL}${path}`, {
      method: "GET",
      headers: buildServerHeaders(cookieHeader, NEXT_PUBLIC_APP_URL, forwardedFor),
      cache: "no-store",
      signal: AbortSignal.timeout(5_000),
    })
  } catch (error) {
    throw new ApiUnavailableError(error)
  }

  const body = await parseBody(response)
  // Fora de try/catch: redirect() funciona lançando uma exceção do Next.
  if (response.status === 401 && redirectOnUnauthenticated) redirect(LOGIN_PATH)
  if (!response.ok) throw new ApiError(response.status, body)
  return body as T
}

export function buildServerHeaders(
  cookieHeader: string,
  appUrl: string,
  forwardedFor?: string | null,
): Headers {
  const headers = new Headers({
    Accept: "application/json",
    Origin: new URL(appUrl).origin,
    Referer: `${new URL(appUrl).origin}/`,
  })
  if (cookieHeader) headers.set("Cookie", cookieHeader)
  if (forwardedFor) headers.set("X-Forwarded-For", forwardedFor)
  return headers
}
