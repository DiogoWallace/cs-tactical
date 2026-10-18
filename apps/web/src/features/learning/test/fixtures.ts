import type {
  AttemptResult,
  AttemptSummary,
  CourseProgress,
  EnrolledProgress,
  EnrollmentSummary,
  OpenAttempt,
} from "../lib/schemas"

/** Respostas da API do Learning (docs/api/academy-v1.md) para os testes do Vitest. */

export function notEnrolled(): CourseProgress {
  return {
    enrolled: false,
    enrolled_at: null,
    completed_at: null,
    percent: null,
    completed_lessons: null,
    total_lessons: null,
    new_lessons_available: false,
    next_lesson: null,
    lessons: [],
  }
}

/** Curso de `features/academy/test/fixtures.ts` (3 aulas), com as aulas indicadas concluídas. */
export function enrolled(
  completed: string[] = [],
  overrides: Partial<EnrolledProgress> = {},
): EnrolledProgress {
  const slugs = ["counter-strafe", "peek-seguro", "crosshair"]
  const titles: Record<string, string> = {
    "counter-strafe": "Counter-strafe",
    "peek-seguro": "Peek seguro",
    crosshair: "Crosshair",
  }
  const next = slugs.find((slug) => !completed.includes(slug)) ?? null

  return {
    enrolled: true,
    enrolled_at: "2026-11-02T13:05:00Z",
    completed_at: next === null ? "2026-11-03T10:00:00Z" : null,
    percent: Math.round((completed.length / slugs.length) * 10_000) / 100,
    completed_lessons: completed.length,
    total_lessons: slugs.length,
    new_lessons_available: false,
    next_lesson: next === null ? null : { slug: next, title: titles[next] ?? next },
    lessons: slugs.map((slug) => ({
      slug,
      status: completed.includes(slug) ? "completed" : "not_started",
      completed_at: completed.includes(slug) ? "2026-11-02T14:00:00Z" : null,
      updated_since_completion: false,
      // counter-strafe tem quiz (fixture do curso da Academy: has_quiz).
      quiz:
        slug === "counter-strafe"
          ? { attempts_used: 0, best_score_percent: null, passed: false }
          : null,
    })),
    ...overrides,
  }
}

export const ATTEMPT_ID = "019a2c00-0000-7000-8000-000000000001"
export const Q1 = "019a2b00-0000-7000-8000-0000000000a1"
export const Q1_A = "019a2b00-0000-7000-8000-0000000000a2"
export const Q1_D = "019a2b00-0000-7000-8000-0000000000a3"
export const Q2 = "019a2b00-0000-7000-8000-0000000000b1"
export const Q2_PEEK = "019a2b00-0000-7000-8000-0000000000b2"
export const Q2_ROT = "019a2b00-0000-7000-8000-0000000000b3"

/** Tentativa aberta: perguntas sem gabarito (formato do início, ADR 0018). */
export function openAttempt(overrides: Partial<OpenAttempt> = {}): OpenAttempt {
  return {
    id: ATTEMPT_ID,
    attempt_number: 1,
    max_attempts: 3,
    status: "in_progress",
    questions: [
      {
        id: Q1,
        kind: "single_choice",
        prompt: "Indo para a esquerda (**A**), qual tecla para mais rápido?",
        options: [
          { id: Q1_A, text: "A" },
          { id: Q1_D, text: "D" },
        ],
      },
      {
        id: Q2,
        kind: "multiple_choice",
        prompt: "Quando usar?",
        options: [
          { id: Q2_PEEK, text: "No peek" },
          { id: Q2_ROT, text: "Na rotação" },
        ],
      },
    ],
    ...overrides,
  }
}

/** Resultado antes da liberação do gabarito: acerto/erro por pergunta, sem `correct` nem explicação. */
export function resultWithoutReview(overrides: Partial<AttemptResult> = {}): AttemptResult {
  return {
    id: ATTEMPT_ID,
    attempt_number: 1,
    quiz_revision: 1,
    started_at: "2026-11-02T14:00:00Z",
    submitted_at: "2026-11-02T14:05:00Z",
    status: "submitted",
    score_percent: 50,
    passed: false,
    question_count: 2,
    correct_count: 1,
    attempts_remaining: 2,
    review_available: false,
    questions: [
      {
        id: Q1,
        kind: "single_choice",
        prompt: "Indo para a esquerda (**A**), qual tecla para mais rápido?",
        selected_option_ids: [Q1_D],
        is_correct: true,
        options: [
          { id: Q1_A, text: "A" },
          { id: Q1_D, text: "D" },
        ],
      },
      {
        id: Q2,
        kind: "multiple_choice",
        prompt: "Quando usar?",
        selected_option_ids: [Q2_ROT],
        is_correct: false,
        options: [
          { id: Q2_PEEK, text: "No peek" },
          { id: Q2_ROT, text: "Na rotação" },
        ],
      },
    ],
    ...overrides,
  } as AttemptResult
}

/** Resultado aprovado, com a revisão liberada: gabarito e explicações do snapshot. */
export function resultWithReview(): AttemptResult {
  return {
    ...resultWithoutReview(),
    score_percent: 100,
    passed: true,
    correct_count: 2,
    review_available: true,
    questions: [
      {
        id: Q1,
        kind: "single_choice",
        prompt: "Indo para a esquerda (**A**), qual tecla para mais rápido?",
        selected_option_ids: [Q1_D],
        is_correct: true,
        options: [
          { id: Q1_A, text: "A", correct: false },
          { id: Q1_D, text: "D", correct: true },
        ],
        explanation: "A tecla oposta zera a velocidade.",
      },
      {
        id: Q2,
        kind: "multiple_choice",
        prompt: "Quando usar?",
        selected_option_ids: [Q2_PEEK],
        is_correct: true,
        options: [
          { id: Q2_PEEK, text: "No peek", correct: true },
          { id: Q2_ROT, text: "Na rotação", correct: false },
        ],
        explanation: null,
      },
    ],
  }
}

export function attemptSummary(overrides: Partial<AttemptSummary> = {}): AttemptSummary {
  return {
    id: ATTEMPT_ID,
    attempt_number: 1,
    quiz_revision: 1,
    started_at: "2026-11-02T14:00:00Z",
    submitted_at: "2026-11-02T14:05:00Z",
    status: "submitted",
    score_percent: 50,
    passed: false,
    attempts_remaining: 2,
    review_available: false,
    ...overrides,
  } as AttemptSummary
}

export function enrollmentSummary(overrides: Partial<EnrollmentSummary> = {}): EnrollmentSummary {
  return {
    course: { slug: "fundamentos-cs2", title: "Fundamentos do CS2", status: "published" },
    enrolled_at: "2026-11-02T13:05:00Z",
    completed_at: null,
    last_activity_at: "2026-11-02T14:00:00Z",
    progress: { completed_lessons: 1, total_lessons: 3, percent: 33.33 },
    next_lesson: { slug: "peek-seguro", title: "Peek seguro" },
    ...overrides,
  }
}
