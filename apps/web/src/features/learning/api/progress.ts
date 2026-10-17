import "server-only"

import { cookies } from "next/headers"
import { cache } from "react"
import { z } from "zod"

import { ApiError, ApiUnavailableError } from "@/lib/api/errors"
import { serverFetch } from "@/lib/api/server"
import { SESSION_COOKIE } from "@/lib/session"
import { isSlug } from "@/features/academy/lib/paths"

import {
  courseProgressResponseSchema,
  enrollmentListSchema,
  type EnrollmentSummary,
  type LearningState,
} from "../lib/schemas"

/**
 * Leituras do Learning no servidor do Next (docs/api/academy-v1.md, etapa 2.4).
 *
 * As páginas da Academy são públicas: sem o cookie de sessão, nem chamamos a API (visitante).
 * Com o cookie, a API decide; 401 (sessão expirada) também vira visitante, sem redirecionar,
 * porque ler o conteúdo não exige login. Nada aqui é cacheado entre usuários (`serverFetch`
 * usa `no-store`); `cache()` só junta as chamadas da mesma requisição.
 */

export type LearningLoad = LearningState | { kind: "unavailable" }

async function hasSession(): Promise<boolean> {
  return (await cookies()).has(SESSION_COOKIE)
}

async function load<T>(
  path: `/${string}`,
  schema: z.ZodType<T>,
): Promise<T | "anonymous" | "unavailable"> {
  let body: unknown
  try {
    body = await serverFetch<unknown>(path, { redirectOnUnauthenticated: false })
  } catch (error) {
    if (error instanceof ApiError && error.status === 401) return "anonymous"
    if (error instanceof ApiError || error instanceof ApiUnavailableError) return "unavailable"
    throw error
  }

  const parsed = schema.safeParse(body)
  if (!parsed.success) {
    console.error(`Learning: resposta fora do formato em ${path}`, z.prettifyError(parsed.error))
    return "unavailable"
  }
  return parsed.data
}

/** Progresso do jogador num curso público (ou visitante). */
export const getCourseProgress = cache(async (course: string): Promise<LearningLoad> => {
  if (!isSlug(course) || !(await hasSession())) return { kind: "anonymous" }

  const result = await load(
    `/api/v1/academy/courses/${course}/progress`,
    courseProgressResponseSchema,
  )
  if (result === "anonymous" || result === "unavailable") return { kind: result }
  return { kind: "player", progress: result.data }
})

export type EnrollmentsLoad =
  | { kind: "anonymous" }
  | { kind: "unavailable" }
  | { kind: "player"; enrollments: EnrollmentSummary[] }

/** Matrículas do jogador ("Meu progresso" e marcações do catálogo). */
export const getMyEnrollments = cache(async (): Promise<EnrollmentsLoad> => {
  if (!(await hasSession())) return { kind: "anonymous" }

  const result = await load("/api/v1/me/enrollments", enrollmentListSchema)
  if (result === "anonymous" || result === "unavailable") return { kind: result }
  return { kind: "player", enrollments: result.data }
})
