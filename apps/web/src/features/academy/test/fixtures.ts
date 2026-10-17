import type { Course, CourseList, CourseSummary, Lesson } from "../lib/schemas"

/** Dados de teste no formato da API (docs/api/academy-v1.md). Só para os testes do Vitest. */

export const SHA = "a".repeat(64)

export function courseSummary(overrides: Partial<CourseSummary> = {}): CourseSummary {
  return {
    slug: "fundamentos-cs2",
    title: "Fundamentos do CS2",
    summary: "Movimentação e mira para quem está começando.",
    level: "beginner",
    cover_image_url: `/api/v1/academy/media/${SHA}.png`,
    module_count: 2,
    lesson_count: 3,
    estimated_minutes: 95,
    ...overrides,
  }
}

export function courseList(
  courses: CourseSummary[] = [courseSummary()],
  meta: Partial<CourseList["meta"]> = {},
): CourseList {
  return {
    data: courses,
    meta: { current_page: 1, last_page: 1, per_page: 20, total: courses.length, ...meta },
  }
}

export function course(overrides: Partial<Course> = {}): Course {
  return {
    slug: "fundamentos-cs2",
    title: "Fundamentos do CS2",
    summary: "Movimentação e mira para quem está começando.",
    level: "beginner",
    cover_image_url: null,
    modules: [
      {
        slug: "movimentacao",
        title: "Movimentação",
        lessons: [
          { slug: "counter-strafe", title: "Counter-strafe", estimated_minutes: 8, has_quiz: true },
          { slug: "peek-seguro", title: "Peek seguro", estimated_minutes: null, has_quiz: false },
        ],
      },
      {
        slug: "mira",
        title: "Mira",
        lessons: [
          { slug: "crosshair", title: "Crosshair", estimated_minutes: 12, has_quiz: false },
        ],
      },
    ],
    ...overrides,
  }
}

export function lesson(overrides: Partial<Lesson> = {}): Lesson {
  return {
    slug: "peek-seguro",
    title: "Peek seguro",
    summary: "Mostre só o necessário.",
    estimated_minutes: 6,
    course: { slug: "fundamentos-cs2", title: "Fundamentos do CS2" },
    module: { slug: "movimentacao", title: "Movimentação" },
    content: {
      version: 1,
      blocks: [{ type: "markdown", data: { text: "## Ângulos\n\nAbra o ângulo aos poucos." } }],
    },
    content_hash: "b".repeat(64),
    previous: { slug: "counter-strafe", title: "Counter-strafe" },
    next: { slug: "crosshair", title: "Crosshair" },
    ...overrides,
  }
}
