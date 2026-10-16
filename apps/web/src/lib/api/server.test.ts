import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

vi.mock("server-only", () => ({}))

const requestHeaders = new Headers()
vi.mock("next/headers", () => ({
  cookies: async () => ({ toString: () => "cs_tactical_session=abc" }),
  headers: async () => requestHeaders,
}))

const redirect = vi.fn((path: string) => {
  throw new Error(`NEXT_REDIRECT:${path}`)
})
vi.mock("next/navigation", () => ({ redirect: (path: string) => redirect(path) }))

const { buildServerHeaders, serverFetch } = await import("./server")
const { ApiError } = await import("./errors")

describe("buildServerHeaders", () => {
  it("forwards the user's cookies and identifies the frontend origin", () => {
    const headers = buildServerHeaders(
      "cs_tactical_session=abc; XSRF-TOKEN=def",
      "http://localhost:3000",
    )

    expect(headers.get("Cookie")).toBe("cs_tactical_session=abc; XSRF-TOKEN=def")
    expect(headers.get("Origin")).toBe("http://localhost:3000")
    expect(headers.get("Referer")).toBe("http://localhost:3000/")
    expect(headers.get("Accept")).toBe("application/json")
  })

  it("omits the Cookie header for anonymous requests", () => {
    expect(buildServerHeaders("", "http://localhost:3000").has("Cookie")).toBe(false)
  })

  it("forwards X-Forwarded-For only when the request has one", () => {
    expect(
      buildServerHeaders("", "http://localhost:3000", "198.51.100.7").get("X-Forwarded-For"),
    ).toBe("198.51.100.7")
    expect(buildServerHeaders("", "http://localhost:3000", null).has("X-Forwarded-For")).toBe(false)
  })
})

describe("serverFetch", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => {
    fetchMock.mockReset()
    redirect.mockClear()
    requestHeaders.delete("x-forwarded-for")
    vi.unstubAllGlobals()
  })

  const unauthenticated = () =>
    new Response(JSON.stringify({ message: "Unauthenticated.", code: "unauthenticated" }), {
      status: 401,
    })

  it("redirects to the login on 401", async () => {
    fetchMock.mockResolvedValueOnce(unauthenticated())

    await expect(serverFetch("/api/v1/me")).rejects.toThrow("NEXT_REDIRECT:/login")
    expect(redirect).toHaveBeenCalledWith("/login")
  })

  it("throws the 401 instead when asked not to redirect", async () => {
    fetchMock.mockResolvedValueOnce(unauthenticated())

    const error = await serverFetch("/api/v1/me", { redirectOnUnauthenticated: false }).catch(
      (e: unknown) => e,
    )

    expect(redirect).not.toHaveBeenCalled()
    expect(error).toBeInstanceOf(ApiError)
    expect((error as InstanceType<typeof ApiError>).code).toBe("unauthenticated")
  })

  it("forwards the client address it received", async () => {
    requestHeaders.set("x-forwarded-for", "198.51.100.7")
    fetchMock.mockResolvedValueOnce(new Response("{}", { status: 200 }))

    await serverFetch("/api/v1/health")

    expect(new Headers(fetchMock.mock.calls[0]![1]?.headers).get("X-Forwarded-For")).toBe(
      "198.51.100.7",
    )
  })
})
