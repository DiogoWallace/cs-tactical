import { apiClient } from "@/lib/api/client"
import { ApiError } from "@/lib/api/errors"

import {
  courseProgressResponseSchema,
  enrollmentResponseSchema,
  type CourseProgress,
} from "../lib/schemas"

/*
 * Escritas e releituras do Learning no navegador, pelo `apiClient` (sessão por cookie, CSRF e
 * 401 levando ao login). O jogador é sempre o da sessão: nada de id de usuário na URL ou no corpo.
 */

/** A resposta chegou, mas fora do formato esperado (a interface trata como falha). */
export class InvalidLearningResponse extends Error {
  constructor() {
    super("Resposta inesperada da API do Learning")
    this.name = "InvalidLearningResponse"
  }
}

function parse<T>(
  schema: { safeParse: (value: unknown) => { success: true; data: T } | { success: false } },
  body: unknown,
): T {
  const parsed = schema.safeParse(body)
  if (!parsed.success) throw new InvalidLearningResponse()
  return parsed.data
}

/** PUT .../enrollment e, em seguida, o progresso atualizado (a matrícula não traz as aulas). */
export async function enrollInCourse(course: string): Promise<CourseProgress> {
  parse(
    enrollmentResponseSchema,
    await apiClient.put<unknown>(`/api/v1/academy/courses/${course}/enrollment`),
  )
  return fetchCourseProgress(course)
}

export async function fetchCourseProgress(course: string): Promise<CourseProgress> {
  const body = await apiClient.get<unknown>(`/api/v1/academy/courses/${course}/progress`)
  return parse(courseProgressResponseSchema, body).data
}

/** PUT .../completion: idempotente; devolve o progresso do curso. */
export async function completeLesson(course: string, lesson: string): Promise<CourseProgress> {
  const body = await apiClient.put<unknown>(
    `/api/v1/academy/courses/${course}/lessons/${lesson}/completion`,
  )
  return parse(courseProgressResponseSchema, body).data
}

/** Texto para o jogador a partir do erro (o `code` da API decide, nunca a mensagem técnica). */
export function learningErrorMessage(error: unknown): string {
  if (error instanceof ApiError) {
    if (error.status === 404) return "Este conteúdo não está mais disponível. Atualize a página."
    if (error.status === 429) return "Muitas ações seguidas. Aguarde um minuto e tente de novo."
    if (error.status === 401) return "Sua sessão expirou. Entre de novo para continuar."
  }
  return "Não foi possível salvar agora. Verifique sua conexão e tente de novo."
}
