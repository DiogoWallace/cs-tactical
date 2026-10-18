import { z } from "zod"

/**
 * Respostas da API do Learning (docs/api/academy-v1.md, etapa 2.4), validadas antes de chegar ao
 * React. A validação não autoriza nada: quem decide o que o jogador vê é a API.
 *
 * Estes schemas também rodam no navegador. Sem `jitless`, o Zod testa `new Function` ao montar
 * schemas de objeto, e a CSP (sem `unsafe-eval`, ADR 0014) registra uma violação. A validação é
 * a mesma; só não há compilação JIT. Precisa vir antes de qualquer schema.
 */
z.config({ jitless: true })

const slug = z
  .string()
  .max(80)
  .regex(/^[a-z0-9]+(?:-[a-z0-9]+)*$/)

const isoDate = z.iso.datetime()

const lessonLink = z.strictObject({ slug, title: z.string().min(1).max(160) })

export const lessonProgressSchema = z.strictObject({
  slug,
  // `in_progress` chega com os quizzes (etapa 2.5).
  status: z.enum(["not_started", "in_progress", "completed"]),
  completed_at: isoDate.nullable(),
  updated_since_completion: z.boolean(),
})

const percent = z.number().min(0).max(100)

/** Matriculado: números presentes. Não matriculado: nulos e lista vazia. */
export const courseProgressSchema = z.discriminatedUnion("enrolled", [
  z.strictObject({
    enrolled: z.literal(true),
    enrolled_at: isoDate,
    completed_at: isoDate.nullable(),
    percent,
    completed_lessons: z.int().min(0),
    total_lessons: z.int().min(0),
    new_lessons_available: z.boolean(),
    next_lesson: lessonLink.nullable(),
    lessons: z.array(lessonProgressSchema),
  }),
  z.strictObject({
    enrolled: z.literal(false),
    enrolled_at: z.null(),
    completed_at: z.null(),
    percent: z.null(),
    completed_lessons: z.null(),
    total_lessons: z.null(),
    new_lessons_available: z.literal(false),
    next_lesson: z.null(),
    lessons: z.array(lessonProgressSchema).length(0),
  }),
])

export const courseProgressResponseSchema = z.strictObject({ data: courseProgressSchema })

export const enrollmentResponseSchema = z.strictObject({
  data: z.strictObject({ course: slug, enrolled_at: isoDate, completed_at: isoDate.nullable() }),
})

export const enrollmentSummarySchema = z.strictObject({
  course: z.strictObject({
    slug,
    title: z.string().min(1).max(160),
    status: z.enum(["published", "archived"]),
  }),
  enrolled_at: isoDate,
  completed_at: isoDate.nullable(),
  last_activity_at: isoDate,
  progress: z.strictObject({
    completed_lessons: z.int().min(0),
    total_lessons: z.int().min(0).nullable(),
    percent: percent.nullable(),
  }),
  next_lesson: lessonLink.nullable(),
})

export const enrollmentListSchema = z.strictObject({ data: z.array(enrollmentSummarySchema) })

export type LessonProgress = z.infer<typeof lessonProgressSchema>
export type CourseProgress = z.infer<typeof courseProgressSchema>
export type EnrolledProgress = Extract<CourseProgress, { enrolled: true }>
export type EnrollmentSummary = z.infer<typeof enrollmentSummarySchema>

/** O que a página sabe do jogador neste curso: visitante (sem sessão) ou o progresso da API. */
export type LearningState = { kind: "anonymous" } | { kind: "player"; progress: CourseProgress }

/** "33,33%" (vírgula decimal, sem zeros sobrando). */
export function formatPercent(value: number): string {
  return `${new Intl.NumberFormat("pt-BR", { maximumFractionDigits: 2 }).format(value)}%`
}
