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

const percent = z.number().min(0).max(100)

/** Resumo do quiz ativo da aula em /progress (etapa 2.5): sem gabarito. */
export const lessonQuizSummarySchema = z.strictObject({
  attempts_used: z.int().min(0),
  best_score_percent: percent.nullable(),
  passed: z.boolean(),
})

export const lessonProgressSchema = z.strictObject({
  slug,
  // `in_progress`: quiz iniciado e aula ainda não concluída (R10).
  status: z.enum(["not_started", "in_progress", "completed"]),
  completed_at: isoDate.nullable(),
  updated_since_completion: z.boolean(),
  quiz: lessonQuizSummarySchema.nullable(),
})

/*
 * Quizzes (etapa 2.5, ADR 0018). O formato vem do único componente da API que aplica a liberação
 * do gabarito (R20–R20b): `correct` e `explanation` só existem com `review_available: true`.
 */
const uuid = z.uuid()

const answerOption = z.strictObject({ id: uuid, text: z.string().min(1).max(500) })

export const openAttemptSchema = z.strictObject({
  id: uuid,
  attempt_number: z.int().min(1),
  max_attempts: z.int().min(1).max(20),
  status: z.literal("in_progress"),
  questions: z
    .array(
      z.strictObject({
        id: uuid,
        kind: z.enum(["single_choice", "multiple_choice"]),
        prompt: z.string().min(1).max(1000),
        options: z.array(answerOption).min(2).max(8),
      }),
    )
    .min(1)
    .max(30),
})

const attemptSummaryFields = {
  id: uuid,
  attempt_number: z.int().min(1),
  quiz_revision: z.int().min(1),
  started_at: isoDate,
  attempts_remaining: z.int().min(0),
}

export const attemptSummarySchema = z.discriminatedUnion("status", [
  z.strictObject({
    ...attemptSummaryFields,
    status: z.literal("in_progress"),
    submitted_at: z.null(),
    score_percent: z.null(),
    passed: z.null(),
    review_available: z.literal(false),
  }),
  z.strictObject({
    ...attemptSummaryFields,
    status: z.literal("submitted"),
    submitted_at: isoDate,
    score_percent: percent,
    passed: z.boolean(),
    review_available: z.boolean(),
  }),
])

const resultQuestionBase = {
  id: uuid,
  kind: z.enum(["single_choice", "multiple_choice"]),
  prompt: z.string().min(1).max(1000),
  selected_option_ids: z.array(uuid),
  is_correct: z.boolean(),
}

const resultFields = {
  ...attemptSummaryFields,
  status: z.literal("submitted"),
  submitted_at: isoDate,
  score_percent: percent,
  passed: z.boolean(),
  question_count: z.int().min(1),
  correct_count: z.int().min(0),
}

export const attemptResultSchema = z.discriminatedUnion("review_available", [
  z.strictObject({
    ...resultFields,
    review_available: z.literal(false),
    questions: z.array(z.strictObject({ ...resultQuestionBase, options: z.array(answerOption) })),
  }),
  z.strictObject({
    ...resultFields,
    review_available: z.literal(true),
    questions: z.array(
      z.strictObject({
        ...resultQuestionBase,
        options: z.array(answerOption.extend({ correct: z.boolean() })),
        explanation: z.string().max(2000).nullable(),
      }),
    ),
  }),
])

export const attemptResponseSchema = z.strictObject({
  data: z.union([openAttemptSchema, attemptResultSchema]),
})

export const attemptListSchema = z.strictObject({ data: z.array(attemptSummarySchema) })

export type OpenAttempt = z.infer<typeof openAttemptSchema>
export type AttemptResult = z.infer<typeof attemptResultSchema>
export type AttemptSummary = z.infer<typeof attemptSummarySchema>
export type AttemptDetail = OpenAttempt | AttemptResult

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
