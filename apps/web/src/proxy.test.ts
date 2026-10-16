// @vitest-environment node
import { NextRequest } from "next/server"
import { describe, expect, it } from "vitest"

import { config, proxy } from "./proxy"

function request(path: string, cookie?: string) {
  return new NextRequest(new URL(path, "http://localhost:3000"), {
    headers: cookie ? { cookie } : {},
  })
}

describe("proxy", () => {
  it("sends visitors without a session cookie to the login, keeping the destination", () => {
    const response = proxy(request("/painel?tab=conta"))

    expect(response.status).toBe(307)
    expect(response.headers.get("location")).toBe(
      "http://localhost:3000/login?next=%2Fpainel%3Ftab%3Dconta",
    )
  })

  it("lets requests with a session cookie through and passes the current path", () => {
    const response = proxy(request("/painel", "cs_tactical_session=abc"))

    expect(response.headers.get("location")).toBeNull()
    expect(response.headers.get("x-middleware-request-x-pathname")).toBe("/painel")
  })

  it("overwrites an x-pathname sent by the client", () => {
    const forged = new NextRequest(new URL("/painel", "http://localhost:3000"), {
      headers: { cookie: "cs_tactical_session=abc", "x-pathname": "//evil.example" },
    })

    expect(proxy(forged).headers.get("x-middleware-request-x-pathname")).toBe("/painel")
  })

  it("only runs on the authenticated area", () => {
    expect(config.matcher).toEqual(["/painel", "/painel/:path*"])
  })
})
