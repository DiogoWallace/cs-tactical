import { describe, expect, it } from "vitest"

import { loginUrl, safeInternalPath } from "./routes"

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
