import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import { apiClient, readXsrfToken, setUnauthenticatedHandler } from "./client"
import { ApiError, isUnauthenticated } from "./errors"

function jsonResponse(status: number, body: unknown = {}) {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "Content-Type": "application/json" },
  })
}

function setCookie(value: string) {
  document.cookie = `XSRF-TOKEN=${encodeURIComponent(value)}; path=/`
}

function clearCookie() {
  document.cookie = "XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/"
}

describe("readXsrfToken", () => {
  it("decodes the cookie value as the browser stores it", () => {
    expect(readXsrfToken("a=1; XSRF-TOKEN=eyJpdiI6%2Babc%3D%3D; b=2")).toBe("eyJpdiI6+abc==")
  })

  it("returns null when the cookie is absent", () => {
    expect(readXsrfToken("a=1")).toBeNull()
  })
})

describe("apiClient", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => {
    vi.stubGlobal("fetch", fetchMock)
    clearCookie()
  })

  afterEach(() => {
    fetchMock.mockReset()
    vi.unstubAllGlobals()
  })

  it("sends GET requests to the same origin without asking for a CSRF cookie", async () => {
    fetchMock.mockResolvedValueOnce(jsonResponse(200, { status: "ok" }))

    await expect(apiClient.get("/api/v1/health")).resolves.toEqual({ status: "ok" })

    expect(fetchMock).toHaveBeenCalledTimes(1)
    const [url, init] = fetchMock.mock.calls[0]!
    expect(url).toBe("/api/v1/health")
    expect(init?.credentials).toBe("same-origin")
    expect(new Headers(init?.headers).get("X-XSRF-TOKEN")).toBeNull()
  })

  it("fetches the CSRF cookie before the first write and sends it as X-XSRF-TOKEN", async () => {
    fetchMock.mockImplementationOnce(async () => {
      setCookie("token+1==")
      return new Response(null, { status: 204 })
    })
    fetchMock.mockResolvedValueOnce(jsonResponse(201, { id: "1" }))

    await apiClient.post("/api/v1/things", { name: "x" })

    expect(fetchMock.mock.calls[0]![0]).toBe("/sanctum/csrf-cookie")
    const [, init] = fetchMock.mock.calls[1]!
    expect(new Headers(init?.headers).get("X-XSRF-TOKEN")).toBe("token+1==")
    expect(init?.body).toBe(JSON.stringify({ name: "x" }))
  })

  it("renews the CSRF cookie and retries once on 419", async () => {
    setCookie("expired")
    fetchMock.mockResolvedValueOnce(jsonResponse(419))
    fetchMock.mockImplementationOnce(async () => {
      setCookie("fresh")
      return new Response(null, { status: 204 })
    })
    fetchMock.mockResolvedValueOnce(jsonResponse(200, { ok: true }))

    await expect(apiClient.post("/api/v1/things")).resolves.toEqual({ ok: true })

    expect(fetchMock).toHaveBeenCalledTimes(3)
    expect(new Headers(fetchMock.mock.calls[2]![1]?.headers).get("X-XSRF-TOKEN")).toBe("fresh")
  })

  it("exposes Laravel validation errors", async () => {
    setCookie("t")
    fetchMock.mockResolvedValueOnce(
      jsonResponse(422, { message: "invalid", errors: { name: ["O nome é obrigatório."] } }),
    )

    const error = await apiClient.post("/api/v1/things").catch((e: unknown) => e)

    expect(error).toBeInstanceOf(ApiError)
    expect((error as ApiError).validationErrors).toEqual({ name: ["O nome é obrigatório."] })
  })

  describe("on 401", () => {
    const onUnauthenticated = vi.fn()

    beforeEach(() => setUnauthenticatedHandler(onUnauthenticated))
    afterEach(() => {
      setUnauthenticatedHandler(null)
      onUnauthenticated.mockReset()
    })

    it("sends the user to the login and still rejects with the error", async () => {
      fetchMock.mockResolvedValueOnce(
        jsonResponse(401, { message: "Unauthenticated.", code: "unauthenticated" }),
      )

      const error = await apiClient.get("/api/v1/me").catch((e: unknown) => e)

      expect(onUnauthenticated).toHaveBeenCalledTimes(1)
      expect(isUnauthenticated(error)).toBe(true)
      expect((error as ApiError).code).toBe("unauthenticated")
    })

    it("does not redirect when the caller expects a 401", async () => {
      fetchMock.mockResolvedValueOnce(jsonResponse(401, { message: "x", code: "unauthenticated" }))

      const error = await apiClient
        .get("/api/v1/me", { redirectOnUnauthenticated: false })
        .catch((e: unknown) => e)

      expect(onUnauthenticated).not.toHaveBeenCalled()
      expect(isUnauthenticated(error)).toBe(true)
    })

    it("does not redirect on other errors", async () => {
      fetchMock.mockResolvedValueOnce(jsonResponse(403, { message: "x", code: "forbidden" }))

      const error = await apiClient.get("/api/v1/things").catch((e: unknown) => e)

      expect(onUnauthenticated).not.toHaveBeenCalled()
      expect((error as ApiError).code).toBe("forbidden")
    })
  })

  it("does not send the redirect option to fetch", async () => {
    fetchMock.mockResolvedValueOnce(jsonResponse(200, {}))

    await apiClient.get("/api/v1/health", { redirectOnUnauthenticated: false })

    expect(fetchMock.mock.calls[0]![1]).not.toHaveProperty("redirectOnUnauthenticated")
  })
})
