import { expect, test, type Page } from "@playwright/test"

import { ACCOUNTS } from "../support/accounts"
import { login } from "../support/auth"

/**
 * CSP nos fluxos reais (docs/architecture/seguranca-http.md).
 * - Política aplicada: nenhuma violação é aceita.
 * - Report-Only (meta sem 'unsafe-inline'): as violações são anexadas ao relatório para
 *   documentar o que falta para aplicá-la; não reprovam o teste.
 */
type Violation = { disposition: string; directive: string; blocked: string; source: string }

async function trackViolations(page: Page) {
  await page.addInitScript(() => {
    const store: unknown[] = []
    Object.defineProperty(window, "__cspViolations", { value: store })
    document.addEventListener("securitypolicyviolation", (event) => {
      store.push({
        disposition: event.disposition,
        directive: event.effectiveDirective,
        blocked: event.blockedURI,
        source: `${event.sourceFile}:${event.lineNumber}`,
      })
    })
  })
}

async function collect(page: Page): Promise<Violation[]> {
  return page.evaluate(
    () => (window as unknown as { __cspViolations?: Violation[] }).__cspViolations ?? [],
  )
}

test("CSP aplicada não bloqueia nada nos fluxos principais", async ({ page }) => {
  await trackViolations(page)
  const seen: Violation[] = []
  const visit = async (step: string) => {
    for (const violation of await collect(page))
      seen.push({ ...violation, source: `${step} ${violation.source}` })
  }

  await page.goto("/")
  await visit("/")
  await page.goto("/cadastro")
  await expect(page.getByRole("option", { name: "Brasil" })).toBeAttached()
  await visit("/cadastro")

  await login(page, ACCOUNTS.csp)
  await visit("/painel")
  await page.getByRole("link", { name: "Conta" }).first().click()
  await expect(page.getByRole("button", { name: "Salvar perfil" })).toBeVisible()
  await visit("/painel/conta")
  await page.getByRole("button", { name: "Alternar tema claro/escuro" }).first().click()
  await visit("tema")

  const enforced = seen.filter((violation) => violation.disposition === "enforce")
  const reportOnly = seen.filter((violation) => violation.disposition === "report")

  const byDirective = new Map<string, Violation[]>()
  for (const violation of reportOnly) {
    byDirective.set(violation.directive, [
      ...(byDirective.get(violation.directive) ?? []),
      violation,
    ])
  }
  const summary = [...byDirective].map(([directive, items]) => ({
    directive,
    count: items.length,
    example: items[0],
  }))
  await test.info().attach("csp-report-only.json", {
    body: JSON.stringify(summary, null, 2),
    contentType: "application/json",
  })
  console.log(`CSP Report-Only: ${JSON.stringify(summary)}`)

  expect(enforced).toEqual([])
})
