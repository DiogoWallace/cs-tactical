import { describe, expect, it } from "vitest"

import { enrolled, enrollmentSummary, notEnrolled } from "../test/fixtures"
import {
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

describe("formatPercent", () => {
  it("uses the Brazilian decimal comma without trailing zeros", () => {
    expect(formatPercent(33.33)).toBe("33,33%")
    expect(formatPercent(100)).toBe("100%")
    expect(formatPercent(0)).toBe("0%")
  })
})
