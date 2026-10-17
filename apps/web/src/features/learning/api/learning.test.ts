import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import { setUnauthenticatedHandler } from "@/lib/api/client"
import { ApiError, ApiUnavailableError } from "@/lib/api/errors"

import { enrolled } from "../test/fixtures"
import {
  completeLesson,
  enrollInCourse,
  InvalidLearningResponse,
  learningErrorMessage,
} from "./learning"

function jsonResponse(status: number, body: unknown) {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "Content-Type": "application/json" },
  })
}

describe("Learning API (browser)", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => {
    vi.stubGlobal("fetch", fetchMock)
    document.cookie = "XSRF-TOKEN=token; path=/"
    setUnauthenticatedHandler(() => {})
  })

  afterEach(() => {
    fetchMock.mockReset()
    vi.unstubAllGlobals()
    setUnauthenticatedHandler(null)
  })

  it("enrolls with PUT (CSRF header, no body, no user id) and then reads the progress", async () => {
    fetchMock
      .mockResolvedValueOnce(
        jsonResponse(201, {
          data: {
            course: "fundamentos-cs2",
            enrolled_at: "2026-11-02T13:05:00Z",
            completed_at: null,
          },
        }),
      )
      .mockResolvedValueOnce(jsonResponse(200, { data: enrolled() }))

    await expect(enrollInCourse("fundamentos-cs2")).resolves.toEqual(enrolled())

    const [path, init] = fetchMock.mock.calls[0]!
    expect(path).toBe("/api/v1/academy/courses/fundamentos-cs2/enrollment")
    expect(init?.method).toBe("PUT")
    expect(init?.body).toBeUndefined()
    expect(new Headers(init?.headers).get("X-XSRF-TOKEN")).toBe("token")
    expect(fetchMock.mock.calls[1]![0]).toBe("/api/v1/academy/courses/fundamentos-cs2/progress")
  })

  it("completes a lesson and returns the updated course progress", async () => {
    fetchMock.mockResolvedValueOnce(jsonResponse(200, { data: enrolled(["counter-strafe"]) }))

    const progress = await completeLesson("fundamentos-cs2", "counter-strafe")

    expect(fetchMock.mock.calls[0]![0]).toBe(
      "/api/v1/academy/courses/fundamentos-cs2/lessons/counter-strafe/completion",
    )
    expect(progress).toMatchObject({ enrolled: true, completed_lessons: 1 })
  })

  it("refuses responses outside the contract", async () => {
    fetchMock.mockResolvedValueOnce(
      jsonResponse(200, { data: { enrolled: true, percent: "muito" } }),
    )

    await expect(completeLesson("fundamentos-cs2", "counter-strafe")).rejects.toBeInstanceOf(
      InvalidLearningResponse,
    )
  })

  it("turns API failures into messages for the player, never the technical text", () => {
    expect(
      learningErrorMessage(new ApiError(404, { message: "Not found.", code: "not_found" })),
    ).toContain("não está mais disponível")
    expect(
      learningErrorMessage(
        new ApiError(429, { message: "Too Many Attempts.", code: "too_many_requests" }),
      ),
    ).toContain("Aguarde um minuto")
    expect(learningErrorMessage(new ApiUnavailableError())).toContain("Verifique sua conexão")
    expect(learningErrorMessage(new InvalidLearningResponse())).toContain("Não foi possível salvar")
  })
})
