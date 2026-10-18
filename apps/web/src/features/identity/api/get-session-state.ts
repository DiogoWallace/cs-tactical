import "server-only"

import { cookies } from "next/headers"
import { cache } from "react"

import { ApiError, ApiUnavailableError } from "@/lib/api/errors"
import { SESSION_COOKIE } from "@/lib/session"

import type { CurrentUser } from "../types"
import { findCurrentUser } from "./get-current-user"

/**
 * Sessão vista pelas páginas públicas (cabeçalho e início). Ver docs/architecture/autenticacao.md.
 *
 * - `visitor`: sem o cookie de sessão (nem chamamos a API) ou sessão expirada (401).
 * - `user`: sessão válida, confirmada pelo /api/v1/me.
 * - `unknown`: há cookie, mas a API não respondeu (fora do ar, erro, limite). Não dá para afirmar
 *   que é visitante nem mostrar dados da conta.
 *
 * Nada aqui é cacheado entre usuários (`serverFetch` usa `no-store`); `cache()` junta as chamadas
 * da mesma requisição, inclusive com `getCurrentUser`/`findCurrentUser` das páginas.
 */
export type SessionState =
  { kind: "visitor" } | { kind: "user"; user: CurrentUser } | { kind: "unknown" }

export const getSessionState = cache(async (): Promise<SessionState> => {
  if (!(await cookies()).has(SESSION_COOKIE)) return { kind: "visitor" }

  try {
    const user = await findCurrentUser()
    return user ? { kind: "user", user } : { kind: "visitor" }
  } catch (error) {
    if (error instanceof ApiError || error instanceof ApiUnavailableError) {
      return { kind: "unknown" }
    }
    throw error
  }
})
