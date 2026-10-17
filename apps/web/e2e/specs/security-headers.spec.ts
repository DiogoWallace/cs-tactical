import { expect, test } from "@playwright/test"

// Headers de segurança na resposta real (build de produção do Next e Nginx da API).
// docs/architecture/seguranca-http.md
test.describe("headers de segurança", () => {
  test("páginas do frontend", async ({ request }) => {
    for (const path of ["/", "/login", "/cadastro"]) {
      const response = await request.get(path)
      const headers = response.headers()

      expect(headers["content-security-policy"], path).toContain("default-src 'self'")
      expect(headers["content-security-policy"], path).toContain("frame-ancestors 'none'")
      expect(headers["content-security-policy"], path).not.toContain("unsafe-eval")
      expect(headers["content-security-policy-report-only"], path).toContain("script-src 'self';")
      expect(headers["x-frame-options"], path).toBe("DENY")
      expect(headers["x-content-type-options"], path).toBe("nosniff")
      expect(headers["referrer-policy"], path).toBe("strict-origin-when-cross-origin")
      expect(headers["permissions-policy"], path).toContain("camera=()")
      expect(headers["cross-origin-opener-policy"], path).toBe("same-origin")
      expect(headers["x-powered-by"], path).toBeUndefined()
      // HSTS é da borda de produção.
      expect(headers["strict-transport-security"], path).toBeUndefined()
    }
  })

  test("respostas da API pelo proxy", async ({ request }) => {
    for (const path of ["/api/v1/health", "/api/v1/me"]) {
      const response = await request.get(path)
      const headers = response.headers()

      expect(headers["content-security-policy"], path).toBe(
        "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'",
      )
      expect(headers["x-frame-options"], path).toBe("DENY")
      expect(headers["x-content-type-options"], path).toBe("nosniff")
      expect(headers["referrer-policy"], path).toBe("no-referrer")
      expect(headers["strict-transport-security"], path).toBeUndefined()
    }
  })
})
