import { describe, expect, it } from "vitest"

import { afterLoginPath, isProtectedPath, loginUrl, safeInternalPath } from "./routes"

describe("safeInternalPath", () => {
  it.each(["/dashboard", "/teams/abc?tab=members", "/"])("accepts %s", (path) => {
    expect(safeInternalPath(path)).toBe(path)
  })

  it.each([
    "https://evil.example",
    "//evil.example",
    "/\\evil.example",
    "/\t/evil.example",
    "/\n/evil.example",
    "javascript:alert(1)",
    "dashboard",
    "",
    null,
    undefined,
  ])("rejects %j", (path) => {
    expect(safeInternalPath(path)).toBeNull()
  })
})

describe("loginUrl", () => {
  it("keeps the page the user was on", () => {
    expect(loginUrl("/dashboard?tab=1")).toBe("/login?next=%2Fdashboard%3Ftab%3D1")
  })

  it("drops external or missing destinations", () => {
    expect(loginUrl("//evil.example")).toBe("/login")
    expect(loginUrl()).toBe("/login")
  })

  it("does not point the login back to itself", () => {
    expect(loginUrl("/login?next=%2Fx")).toBe("/login")
  })
})

describe("afterLoginPath", () => {
  it("returns to the page the user wanted", () => {
    expect(afterLoginPath("/painel?tab=conta")).toBe("/painel?tab=conta")
  })

  it("falls back to the dashboard for missing, external or login destinations", () => {
    expect(afterLoginPath(null)).toBe("/painel")
    expect(afterLoginPath("https://evil.example")).toBe("/painel")
    expect(afterLoginPath("//evil.example")).toBe("/painel")
    expect(afterLoginPath("/login?next=%2Fpainel")).toBe("/painel")
  })
})

describe("isProtectedPath", () => {
  it.each(["/painel", "/painel/conta"])("protects %s", (path) => {
    expect(isProtectedPath(path)).toBe(true)
  })

  it.each(["/", "/login", "/cadastro", "/status", "/painelx"])("leaves %s public", (path) => {
    expect(isProtectedPath(path)).toBe(false)
  })
})
