import "server-only"

import { cache } from "react"

import { isUnauthenticated } from "@/lib/api/errors"
import { serverFetch } from "@/lib/api/server"

import type { CurrentUser, CurrentUserResponse } from "../types"

/**
 * Usuário da sessão atual (GET /api/v1/me). Sem sessão válida, redireciona ao login.
 * `cache` evita chamadas repetidas à API quando layout e página pedem o usuário na mesma requisição.
 */
export const getCurrentUser = cache(async (): Promise<CurrentUser> => {
  const response = await serverFetch<CurrentUserResponse>("/api/v1/me")
  return response.data
})

/** Igual, mas devolve null sem sessão, em vez de redirecionar (páginas públicas e de login). */
export const findCurrentUser = cache(async (): Promise<CurrentUser | null> => {
  try {
    const response = await serverFetch<CurrentUserResponse>("/api/v1/me", {
      redirectOnUnauthenticated: false,
    })
    return response.data
  } catch (error) {
    if (isUnauthenticated(error)) return null
    throw error
  }
})
