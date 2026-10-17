import { beforeEach, describe, expect, it, vi } from "vitest"

vi.mock("server-only", () => ({}))

const serverFetch = vi.fn()
vi.mock("@/lib/api/server", () => ({ serverFetch: (...args: unknown[]) => serverFetch(...args) }))

const { ApiError, ApiUnavailableError } = await import("@/lib/api/errors")
const { catalogFilters, getCourse, getCourses, getLesson } = await import("./catalog")

const SHA = "a".repeat(64)

function lessonBody(blocks: unknown[]) {
  return {
    data: {
      slug: "counter-strafe",
      title: "Counter-strafe",
      summary: null,
      estimated_minutes: 8,
      course: { slug: "fundamentos-cs2", title: "Fundamentos" },
      module: { slug: "movimentacao", title: "Movimentação" },
      content: { version: 1, blocks },
      content_hash: SHA,
      previous: null,
      next: null,
    },
  }
}

beforeEach(() => {
  serverFetch.mockReset()
  vi.spyOn(console, "error").mockImplementation(() => {})
})

describe("catalogFilters", () => {
  it("keeps only valid levels and pages", () => {
    expect(catalogFilters({})).toEqual({ level: null, page: 1 })
    expect(catalogFilters({ level: "advanced", page: "3" })).toEqual({ level: "advanced", page: 3 })
    expect(catalogFilters({ level: "pro", page: "0" })).toEqual({ level: null, page: 1 })
    expect(catalogFilters({ level: ["beginner", "advanced"], page: "1.5" })).toEqual({
      level: null,
      page: 1,
    })
    expect(catalogFilters({ page: "99999999" })).toEqual({ level: null, page: 1 })
    expect(catalogFilters({ page: "abc" })).toEqual({ level: null, page: 1 })
  })
})

describe("getCourses", () => {
  it("calls the public list with only the supported filters, never include_unpublished", async () => {
    serverFetch.mockResolvedValue({
      data: [],
      meta: { current_page: 2, last_page: 2, per_page: 20, total: 21 },
    })

    const result = await getCourses("intermediate", 2)

    expect(serverFetch).toHaveBeenCalledWith("/api/v1/academy/courses?level=intermediate&page=2", {
      redirectOnUnauthenticated: false,
    })
    expect(String(serverFetch.mock.calls[0]?.[0])).not.toContain("include_unpublished")
    expect(result).toEqual({
      status: "ok",
      data: { data: [], meta: { current_page: 2, last_page: 2, per_page: 20, total: 21 } },
    })
  })

  it.each([
    ["API fora do ar", new ApiUnavailableError()],
    ["erro do servidor", new ApiError(503, { code: "service_unavailable" })],
    ["limite de requisições", new ApiError(429, { code: "too_many_requests" })],
  ])("reports %s as unavailable", async (_name, error) => {
    serverFetch.mockRejectedValue(error)
    expect(await getCourses(null, 1)).toEqual({ status: "unavailable" })
  })

  it("treats a response in an unexpected shape as unavailable", async () => {
    serverFetch.mockResolvedValue({ data: [{ slug: "x" }] })
    expect(await getCourses(null, 1)).toEqual({ status: "unavailable" })
  })
})

describe("getCourse", () => {
  it("maps 404 to not found", async () => {
    serverFetch.mockRejectedValue(new ApiError(404, { code: "not_found" }))
    expect(await getCourse("rascunho")).toEqual({ status: "not_found" })
  })

  it.each(["../me", "Fundamentos", "a/b", "a%2Fb", "x".repeat(81), ""])(
    "never puts an invalid slug (%s) in the API path",
    async (slug) => {
      expect(await getCourse(slug)).toEqual({ status: "not_found" })
      expect(serverFetch).not.toHaveBeenCalled()
    },
  )

  it("unwraps the data envelope", async () => {
    serverFetch.mockResolvedValue({
      data: {
        slug: "c",
        title: "C",
        summary: "S",
        level: "beginner",
        cover_image_url: null,
        modules: [],
      },
    })
    expect(await getCourse("c")).toEqual({
      status: "ok",
      data: {
        slug: "c",
        title: "C",
        summary: "S",
        level: "beginner",
        cover_image_url: null,
        modules: [],
      },
    })
    expect(serverFetch).toHaveBeenCalledWith("/api/v1/academy/courses/c", {
      redirectOnUnauthenticated: false,
    })
  })
})

describe("getLesson", () => {
  it("drops invalid blocks and keeps the valid ones", async () => {
    serverFetch.mockResolvedValue(
      lessonBody([
        { type: "markdown", data: { text: "## Ok" } },
        { type: "html", data: { html: "<script>alert(1)</script>" } },
        {
          type: "image",
          data: { url: "https://evil.example/x.png", alt: "", caption: null, width: 1, height: 1 },
        },
      ]),
    )

    const result = await getLesson("fundamentos-cs2", "counter-strafe")

    expect(result.status).toBe("ok")
    expect(result.status === "ok" && result.data.content.blocks).toEqual([
      { type: "markdown", data: { text: "## Ok" } },
    ])
    expect(serverFetch).toHaveBeenCalledWith(
      "/api/v1/academy/courses/fundamentos-cs2/lessons/counter-strafe",
      {
        redirectOnUnauthenticated: false,
      },
    )
  })

  it("does not call the API for an invalid lesson slug", async () => {
    expect(await getLesson("fundamentos-cs2", "../../me")).toEqual({ status: "not_found" })
    expect(serverFetch).not.toHaveBeenCalled()
  })

  it("maps 404 to not found (draft, archived or another course's lesson)", async () => {
    serverFetch.mockRejectedValue(new ApiError(404, { code: "not_found" }))
    expect(await getLesson("fundamentos-cs2", "rascunho")).toEqual({ status: "not_found" })
  })
})
