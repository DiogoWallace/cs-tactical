import type { CourseProgress, EnrolledProgress, EnrollmentSummary } from "../lib/schemas"

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
    })),
    ...overrides,
  }
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
