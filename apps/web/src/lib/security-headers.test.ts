import { describe, expect, it } from "vitest"

import {
  enforcedCsp,
  PAGE_HEADERS_SOURCE,
  reportOnlyCsp,
  securityHeaders,
} from "./security-headers"

function directives(csp: string): Map<string, string[]> {
  return new Map(
    csp.split(";").map((part) => {
      const [name = "", ...values] = part.trim().split(/\s+/)
      return [name, values] as const
    }),
  )
}

describe("security headers", () => {
  it("keeps the enforced CSP restricted to the own origin", () => {
    const csp = directives(enforcedCsp(false))

    expect(csp.get("default-src")).toEqual(["'self'"])
    expect(csp.get("connect-src")).toEqual(["'self'"])
    expect(csp.get("object-src")).toEqual(["'none'"])
    expect(csp.get("frame-ancestors")).toEqual(["'none'"])
    expect(csp.get("base-uri")).toEqual(["'self'"])
    expect(csp.get("form-action")).toEqual(["'self'"])
    // Nenhuma origem externa e nenhum curinga em qualquer diretiva.
    for (const values of csp.values()) {
      expect(values.join(" ")).not.toMatch(/\*|https?:/)
    }
  })

  it("allows eval only in development", () => {
    expect(enforcedCsp(false)).not.toContain("'unsafe-eval'")
    expect(enforcedCsp(true)).toContain("'unsafe-eval'")
  })

  it("keeps the report-only CSP free of inline scripts and styles", () => {
    const csp = directives(reportOnlyCsp(false))

    expect(csp.get("script-src")).toEqual(["'self'"])
    expect(csp.get("style-src")).toEqual(["'self'"])
  })

  it("leaves HSTS to the production edge", () => {
    const keys = securityHeaders(false).map((header) => header.key.toLowerCase())

    expect(keys).not.toContain("strict-transport-security")
    expect(enforcedCsp(false)).not.toContain("upgrade-insecure-requests")
  })

  it("applies to pages but not to the routes proxied to the API", () => {
    const pattern = new RegExp(`^${PAGE_HEADERS_SOURCE}$`)

    expect(pattern.test("/painel")).toBe(true)
    expect(pattern.test("/login")).toBe(true)
    expect(pattern.test("/api/v1/me")).toBe(false)
    expect(pattern.test("/sanctum/csrf-cookie")).toBe(false)
  })
})
