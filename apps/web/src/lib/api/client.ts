import { loginUrl } from "@/lib/routes"

import { ApiError, ApiUnavailableError, parseBody } from "./errors"

/**
 * Chamadas à API feitas no navegador (Client Components). Ver docs/architecture/autenticacao.md.
 *
 * - Caminhos relativos (/api/v1/...): o Next encaminha para o Laravel pela mesma origem.
 * - O cookie de sessão é HTTP-only e vai automaticamente; nenhum token fica em JavaScript.
 * - Escritas enviam o header X-XSRF-TOKEN com o valor do cookie XSRF-TOKEN.
 * - Um 419 (token CSRF expirado) renova o cookie e repete a requisição uma vez.
 * - Um 401 (sessão inexistente ou expirada) leva ao login, voltando depois para a página atual.
 *   Quem espera um 401 (ex.: checar se há sessão) passa `redirectOnUnauthenticated: false`.
 */

const SAFE_METHODS = new Set(["GET", "HEAD", "OPTIONS"])

type SendOptions = Omit<RequestInit, "body"> & { body?: unknown }

type RequestOptions = SendOptions & {
  /** Padrão: true. Com false, o 401 só é lançado como ApiError, sem redirecionar. */
  redirectOnUnauthenticated?: boolean
}

type UnauthenticatedHandler = () => void

function redirectToLogin(): void {
  const { pathname, search } = window.location
  const target = loginUrl(`${pathname}${search}`)
  // Já no login: redirecionar de novo só criaria um laço.
  if (pathname === target.split("?")[0]) return
  window.location.assign(target)
}

let unauthenticatedHandler: UnauthenticatedHandler = redirectToLogin

/** Troca a reação ao 401 (usado nos testes; o padrão é redirecionar ao login). */
export function setUnauthenticatedHandler(handler: UnauthenticatedHandler | null): void {
  unauthenticatedHandler = handler ?? redirectToLogin
}

export function readXsrfToken(cookieString: string = document.cookie): string | null {
  const entry = cookieString.split("; ").find((cookie) => cookie.startsWith("XSRF-TOKEN="))
  return entry ? decodeURIComponent(entry.slice("XSRF-TOKEN=".length)) : null
}

export async function ensureCsrfCookie(force = false): Promise<void> {
  if (!force && readXsrfToken()) return
  await send("/sanctum/csrf-cookie", { method: "GET" })
}

async function send(path: string, { body, headers, ...init }: SendOptions): Promise<Response> {
  const method = (init.method ?? "GET").toUpperCase()
  const finalHeaders = new Headers(headers)
  finalHeaders.set("Accept", "application/json")

  if (body !== undefined) finalHeaders.set("Content-Type", "application/json")
  if (!SAFE_METHODS.has(method)) {
    const token = readXsrfToken()
    if (token) finalHeaders.set("X-XSRF-TOKEN", token)
  }

  try {
    return await fetch(path, {
      ...init,
      method,
      headers: finalHeaders,
      body: body === undefined ? undefined : JSON.stringify(body),
      credentials: "same-origin",
    })
  } catch (error) {
    throw new ApiUnavailableError(error)
  }
}

export async function apiRequest<T>(
  path: `/${string}`,
  { redirectOnUnauthenticated = true, ...options }: RequestOptions = {},
): Promise<T> {
  const method = (options.method ?? "GET").toUpperCase()
  if (!SAFE_METHODS.has(method)) await ensureCsrfCookie()

  let response = await send(path, options)

  if (response.status === 419) {
    await ensureCsrfCookie(true)
    response = await send(path, options)
  }

  const body = await parseBody(response)
  if (!response.ok) {
    if (response.status === 401 && redirectOnUnauthenticated) {
      unauthenticatedHandler()
    }
    throw new ApiError(response.status, body)
  }
  return body as T
}

export const apiClient = {
  get: <T>(path: `/${string}`, init?: RequestOptions) =>
    apiRequest<T>(path, { ...init, method: "GET" }),
  post: <T>(path: `/${string}`, body?: unknown, init?: RequestOptions) =>
    apiRequest<T>(path, { ...init, method: "POST", body }),
  put: <T>(path: `/${string}`, body?: unknown, init?: RequestOptions) =>
    apiRequest<T>(path, { ...init, method: "PUT", body }),
  patch: <T>(path: `/${string}`, body?: unknown, init?: RequestOptions) =>
    apiRequest<T>(path, { ...init, method: "PATCH", body }),
  delete: <T>(path: `/${string}`, init?: RequestOptions) =>
    apiRequest<T>(path, { ...init, method: "DELETE" }),
}
