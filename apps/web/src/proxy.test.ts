// @vitest-environment node
import { NextRequest } from "next/server"
import { afterEach, describe, expect, it, vi } from "vitest"

vi.mock("server-only", () => ({}))

const { config, proxy } = await import("./proxy")

const REMEMBER = "remember_web_59ba36=old%3D%3D"

function request(path: string, cookie?: string) {
  return new NextRequest(new URL(path, "http://localhost:3000"), {
    headers: cookie ? { cookie, "x-forwarded-for": "198.51.100.7" } : {},
  })
}

function apiResponse(status: number, setCookies: string[] = []) {
  const headers = new Headers()
  for (const cookie of setCookies) headers.append("set-cookie", cookie)
  return new Response(status === 200 ? '{"data":{}}' : null, { status, headers })
}

describe("proxy", () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it("sends visitors without a session cookie to the login, keeping the destination", async () => {
    const response = await proxy(request("/painel?tab=conta"))

    expect(response.status).toBe(307)
    expect(response.headers.get("location")).toBe(
      "http://localhost:3000/login?next=%2Fpainel%3Ftab%3Dconta",
    )
  })

  it("lets requests with a session cookie through and passes the current path", async () => {
    const fetch = vi.fn()
    vi.stubGlobal("fetch", fetch)

    const response = await proxy(request("/painel", "cs_tactical_session=abc"))

    expect(response.headers.get("location")).toBeNull()
    expect(response.headers.get("x-middleware-request-x-pathname")).toBe("/painel")
    expect(fetch).not.toHaveBeenCalled()
  })

  it("overwrites an x-pathname sent by the client", async () => {
    const forged = new NextRequest(new URL("/painel", "http://localhost:3000"), {
      headers: { cookie: "cs_tactical_session=abc", "x-pathname": "//evil.example" },
    })

    expect((await proxy(forged)).headers.get("x-middleware-request-x-pathname")).toBe("/painel")
  })

  describe("expired session with a remember-me cookie", () => {
    it("renews the session through the API and hands the new cookies to the browser", async () => {
      const fetch = vi
        .fn()
        .mockResolvedValue(
          apiResponse(200, [
            "XSRF-TOKEN=tok%3D; path=/; samesite=lax",
            "cs_tactical_session=new%3D%3D; path=/; httponly; samesite=lax",
          ]),
        )
      vi.stubGlobal("fetch", fetch)

      const response = await proxy(request("/painel/academy", REMEMBER))

      expect(response.headers.get("location")).toBeNull()
      expect(response.headers.getSetCookie()).toEqual([
        "XSRF-TOKEN=tok%3D; path=/; samesite=lax",
        "cs_tactical_session=new%3D%3D; path=/; httponly; samesite=lax",
      ])
      // Os Server Components desta mesma página já veem a sessão nova, sem decodificar valores.
      expect(response.headers.get("x-middleware-request-cookie")).toBe(
        "remember_web_59ba36=old%3D%3D; XSRF-TOKEN=tok%3D; cs_tactical_session=new%3D%3D",
      )

      expect(fetch).toHaveBeenCalledOnce()
      const [url, init] = fetch.mock.calls[0] as [string, RequestInit]
      expect(url).toBe("http://api:8080/api/v1/me")
      const headers = init.headers as Headers
      expect(headers.get("Cookie")).toBe(REMEMBER)
      expect(headers.get("Origin")).toBe("http://localhost:3000")
      expect(headers.get("X-Forwarded-For")).toBe("198.51.100.7")
    })

    it.each([
      ["the API rejects the remember-me cookie", () => Promise.resolve(apiResponse(401))],
      ["the API answers without a session cookie", () => Promise.resolve(apiResponse(200))],
      ["the API is rate limiting", () => Promise.resolve(apiResponse(429))],
      ["the API is down", () => Promise.reject(new TypeError("fetch failed"))],
    ])("goes to the login when %s", async (_, answer) => {
      vi.stubGlobal("fetch", vi.fn(answer))

      const response = await proxy(request("/painel", REMEMBER))

      expect(response.status).toBe(307)
      expect(response.headers.get("location")).toBe("http://localhost:3000/login?next=%2Fpainel")
      expect(response.headers.getSetCookie()).toEqual([])
    })
  })

  it("only runs on the authenticated area", () => {
    expect(config.matcher).toEqual(["/painel", "/painel/:path*"])
  })
})
