import "server-only"

import { cookies } from "next/headers"

import { serverEnv } from "@/lib/env"

import { ApiError, ApiUnavailableError, parseBody } from "./errors"

/**
 * Chamadas à API feitas no servidor (Server Components). Ver docs/architecture/autenticacao.md.
 *
 * - Vai direto para a API pela rede interna, sem passar pelo proxy.
 * - Repassa os cookies da requisição do usuário, para que o Laravel reconheça a sessão.
 * - Envia Origin/Referer do frontend, para o Sanctum tratar a chamada como "stateful".
 * - Nunca usa cache: respostas autenticadas não podem ser compartilhadas entre usuários.
 *
 * Somente leitura (GET). Escritas acontecem no navegador via `apiClient`, porque cookies
 * renovados pela API numa chamada feita no servidor não chegariam ao navegador.
 *
 * Com Cache Components, quem chama esta função precisa estar dentro de <Suspense>.
 */
export async function serverFetch<T>(path: `/${string}`): Promise<T> {
  const { API_INTERNAL_URL, NEXT_PUBLIC_APP_URL } = serverEnv()
  const cookieHeader = (await cookies()).toString()

  let response: Response
  try {
    response = await fetch(`${API_INTERNAL_URL}${path}`, {
      method: "GET",
      headers: buildServerHeaders(cookieHeader, NEXT_PUBLIC_APP_URL),
      cache: "no-store",
      signal: AbortSignal.timeout(5_000),
    })
  } catch (error) {
    throw new ApiUnavailableError(error)
  }

  const body = await parseBody(response)
  if (!response.ok) throw new ApiError(response.status, body)
  return body as T
}

export function buildServerHeaders(cookieHeader: string, appUrl: string): Headers {
  const headers = new Headers({
    Accept: "application/json",
    Origin: new URL(appUrl).origin,
    Referer: `${new URL(appUrl).origin}/`,
  })
  if (cookieHeader) headers.set("Cookie", cookieHeader)
  return headers
}
