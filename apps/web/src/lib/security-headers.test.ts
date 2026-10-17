import { describe, expect, it } from "vitest"

import {
  enforcedCsp,
  LESSON_HEADERS_SOURCE,
  lessonCspHeaders,
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

describe("lesson pages CSP (ADR 0019, item 4)", () => {
  it("keeps frames blocked on every other page", () => {
    expect(directives(enforcedCsp(false)).get("frame-src")).toEqual(["'none'"])
    expect(directives(reportOnlyCsp(false)).get("frame-src")).toEqual(["'none'"])
  })

  it("only adds the privacy-enhanced YouTube player to frame-src", () => {
    const headers = new Map(lessonCspHeaders(false).map((header) => [header.key, header.value]))
    const enforced = headers.get("Content-Security-Policy") ?? ""
    const reportOnly = headers.get("Content-Security-Policy-Report-Only") ?? ""
    const player = "frame-src https://www.youtube-nocookie.com"

    expect(directives(enforced).get("frame-src")).toEqual(["https://www.youtube-nocookie.com"])
    expect(directives(reportOnly).get("frame-src")).toEqual(["https://www.youtube-nocookie.com"])
    // Fora o frame-src, as duas políticas são idênticas às das demais páginas.
    expect(enforced.replace(player, "frame-src 'none'")).toBe(enforcedCsp(false))
    expect(reportOnly.replace(player, "frame-src 'none'")).toBe(reportOnlyCsp(false))
  })

  it("matches only lesson pages", () => {
    const pattern = new RegExp(`^${LESSON_HEADERS_SOURCE.replace(/:[a-z]+/g, "[^/]+")}$`)

    expect(pattern.test("/academy/cursos/fundamentos-cs2/aulas/counter-strafe")).toBe(true)
    expect(pattern.test("/academy/cursos/fundamentos-cs2")).toBe(false)
    expect(pattern.test("/academy")).toBe(false)
    expect(pattern.test("/painel")).toBe(false)
  })
})
