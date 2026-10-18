import { describe, expect, it } from "vitest"

import {
  attemptSummary,
  enrolled,
  enrollmentSummary,
  notEnrolled,
  openAttempt,
  Q1_A,
  Q1_D,
  resultWithoutReview,
  resultWithReview,
} from "../test/fixtures"
import {
  attemptListSchema,
  attemptResponseSchema,
  courseProgressResponseSchema,
  enrollmentListSchema,
  enrollmentResponseSchema,
  formatPercent,
} from "./schemas"

describe("courseProgressResponseSchema", () => {
  it("accepts the enrolled and the not-enrolled shapes from the API", () => {
    expect(
      courseProgressResponseSchema.safeParse({ data: enrolled(["counter-strafe"]) }).success,
    ).toBe(true)
    expect(courseProgressResponseSchema.safeParse({ data: notEnrolled() }).success).toBe(true)
  })

  it("rejects unexpected fields, so internal data never reaches the page unnoticed", () => {
    const withId = { data: { ...enrolled(), user_id: "123" } }
    const lessonWithHash = {
      data: enrolled([], {
        lessons: [
          {
            slug: "a",
            status: "completed",
            completed_at: null,
            updated_since_completion: false,
            content_hash: "x",
          },
        ] as never,
      }),
    }

    expect(courseProgressResponseSchema.safeParse(withId).success).toBe(false)
    expect(courseProgressResponseSchema.safeParse(lessonWithHash).success).toBe(false)
  })

  it("rejects incoherent values", () => {
    expect(
      courseProgressResponseSchema.safeParse({ data: enrolled([], { percent: 120 }) }).success,
    ).toBe(false)
    expect(
      courseProgressResponseSchema.safeParse({
        data: { ...notEnrolled(), lessons: enrolled().lessons },
      }).success,
    ).toBe(false)
    expect(
      courseProgressResponseSchema.safeParse({
        data: enrolled([], { next_lesson: { slug: "../me", title: "x" } }),
      }).success,
    ).toBe(false)
  })
})

describe("enrollment schemas", () => {
  it("accepts the enrollment and the enrollment list", () => {
    expect(
      enrollmentResponseSchema.safeParse({
        data: {
          course: "fundamentos-cs2",
          enrolled_at: "2026-11-02T13:05:00Z",
          completed_at: null,
        },
      }).success,
    ).toBe(true)
    expect(
      enrollmentListSchema.safeParse({
        data: [
          enrollmentSummary(),
          enrollmentSummary({
            course: { slug: "antigo", title: "Antigo", status: "archived" },
            progress: { completed_lessons: 2, total_lessons: null, percent: null },
            next_lesson: null,
          }),
        ],
      }).success,
    ).toBe(true)
  })

  it("rejects courses in states the player can never be enrolled in", () => {
    const draft = enrollmentSummary({ course: { slug: "x", title: "X", status: "draft" as never } })
    expect(enrollmentListSchema.safeParse({ data: [draft] }).success).toBe(false)
  })
})

describe("quiz attempt schemas", () => {
  it("accept the open attempt, the results and the history", () => {
    expect(attemptResponseSchema.safeParse({ data: openAttempt() }).success).toBe(true)
    expect(attemptResponseSchema.safeParse({ data: resultWithoutReview() }).success).toBe(true)
    expect(attemptResponseSchema.safeParse({ data: resultWithReview() }).success).toBe(true)
    expect(attemptListSchema.safeParse({ data: [attemptSummary()] }).success).toBe(true)
  })

  it("reject any answer key in an open attempt or in a result before the review", () => {
    const open = openAttempt()
    const leakedOpen = {
      ...open,
      questions: [
        {
          ...open.questions[0]!,
          options: [
            { id: Q1_D, text: "D", correct: true },
            { id: Q1_A, text: "A" },
          ],
        },
      ],
    }
    const leakedResult = {
      ...resultWithoutReview(),
      questions: resultWithoutReview().questions.map((question) => ({
        ...question,
        explanation: "x",
      })),
    }

    expect(attemptResponseSchema.safeParse({ data: leakedOpen }).success).toBe(false)
    expect(attemptResponseSchema.safeParse({ data: leakedResult }).success).toBe(false)
  })

  it("accepts the quiz summary in /progress lessons", () => {
    const progress = enrolled([], {
      lessons: [
        {
          slug: "a",
          status: "in_progress",
          completed_at: null,
          updated_since_completion: false,
          quiz: { attempts_used: 1, best_score_percent: null, passed: false },
        },
      ],
    })
    expect(courseProgressResponseSchema.safeParse({ data: progress }).success).toBe(true)
  })
})

describe("formatPercent", () => {
  it("uses the Brazilian decimal comma without trailing zeros", () => {
    expect(formatPercent(33.33)).toBe("33,33%")
    expect(formatPercent(100)).toBe("100%")
    expect(formatPercent(0)).toBe("0%")
  })
})
