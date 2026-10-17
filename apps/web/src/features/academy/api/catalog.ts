import "server-only"

import { cache } from "react"
import { z } from "zod"

import { ApiError, ApiUnavailableError } from "@/lib/api/errors"
import { serverFetch } from "@/lib/api/server"

import { isSlug } from "../lib/paths"
import {
  COURSE_LEVELS,
  courseListSchema,
  courseSchema,
  lessonSchema,
  parseBlocks,
  type Course,
  type CourseLevel,
  type CourseList,
  type Lesson,
} from "../lib/schemas"

/**
 * Leitura do catálogo pela API real (docs/api/academy-v1.md), no servidor do Next.
 *
 * - Usa o `serverFetch` (rede interna, sem cache, com X-Forwarded-For para o limite por IP).
 * - Nunca pede `include_unpublished`: as páginas públicas mostram o mesmo conteúdo a todos e
 *   nada editorial é renderizado, pré-renderizado ou colocado em metadados.
 * - Slugs, nível e página são conferidos antes de entrar na URL da API.
 * - A resposta passa pelos schemas Zod; fora do formato, a página trata como indisponível.
 * - `cache()` do React: a página e o `generateMetadata` da mesma requisição fazem uma só chamada.
 */

export type Loaded<T> =
  { status: "ok"; data: T } | { status: "not_found" } | { status: "unavailable" }

export const MAX_PAGE = 10_000

/** Filtros da listagem a partir da URL; valores inválidos voltam ao padrão. */
export function catalogFilters(searchParams: Record<string, string | string[] | undefined>): {
  level: CourseLevel | null
  page: number
} {
  const level = typeof searchParams.level === "string" ? searchParams.level : null
  const page = typeof searchParams.page === "string" ? Number(searchParams.page) : 1

  return {
    level: COURSE_LEVELS.find((value) => value === level) ?? null,
    page: Number.isInteger(page) && page >= 1 && page <= MAX_PAGE ? page : 1,
  }
}

async function load<T>(path: `/${string}`, schema: z.ZodType<T>): Promise<Loaded<T>> {
  let body: unknown
  try {
    body = await serverFetch<unknown>(path, { redirectOnUnauthenticated: false })
  } catch (error) {
    if (error instanceof ApiError && error.status === 404) return { status: "not_found" }
    // 429, 5xx, 422 inesperado, rede: a página mostra "indisponível", sem detalhes técnicos.
    if (error instanceof ApiError || error instanceof ApiUnavailableError) {
      return { status: "unavailable" }
    }
    throw error
  }

  const parsed = schema.safeParse(body)
  if (!parsed.success) {
    console.error(`Academy: resposta fora do formato em ${path}`, z.prettifyError(parsed.error))
    return { status: "unavailable" }
  }
  return { status: "ok", data: parsed.data }
}

export const getCourses = cache(
  async (level: CourseLevel | null, page: number): Promise<Loaded<CourseList>> => {
    const query = new URLSearchParams()
    if (level) query.set("level", level)
    if (page > 1) query.set("page", String(page))
    const suffix = query.size > 0 ? `?${query}` : ""

    return load(`/api/v1/academy/courses${suffix}`, courseListSchema)
  },
)

export const getCourse = cache(async (course: string): Promise<Loaded<Course>> => {
  if (!isSlug(course)) return { status: "not_found" }

  const result = await load(`/api/v1/academy/courses/${course}`, z.object({ data: courseSchema }))
  return result.status === "ok" ? { status: "ok", data: result.data.data } : result
})

export const getLesson = cache(async (course: string, lesson: string): Promise<Loaded<Lesson>> => {
  if (!isSlug(course) || !isSlug(lesson)) return { status: "not_found" }

  const result = await load(
    `/api/v1/academy/courses/${course}/lessons/${lesson}`,
    z.object({ data: lessonSchema }),
  )
  if (result.status !== "ok") return result

  const { blocks, dropped } = parseBlocks(result.data.data.content.blocks)
  if (dropped > 0) {
    console.error(`Academy: ${dropped} bloco(s) inválido(s) omitido(s) em ${course}/${lesson}`)
  }
  return { status: "ok", data: { ...result.data.data, content: { version: 1, blocks } } }
})
