import { ACADEMY_PATH } from "@/lib/routes"

export { ACADEMY_PATH }

/**
 * Rotas da Academy no frontend (docs/database/academy-learning.md: `/academy/cursos/{curso}/aulas/{aula}`).
 * Slugs seguem o formato da API (`^[a-z0-9]+(-[a-z0-9]+)*$`, até 80 caracteres).
 */

const SLUG = /^[a-z0-9]+(?:-[a-z0-9]+)*$/

/**
 * Slug no formato aceito pela API. Um valor fora dele nunca entra no caminho da chamada à API
 * (ex.: "../me" viraria outra rota do Laravel com os cookies do usuário): a página responde 404.
 */
export function isSlug(value: unknown): value is string {
  return typeof value === "string" && value.length <= 80 && SLUG.test(value)
}

export function coursePath(course: string): string {
  return `${ACADEMY_PATH}/cursos/${course}`
}

export function lessonPath(course: string, lesson: string): string {
  return `${coursePath(course)}/aulas/${lesson}`
}
