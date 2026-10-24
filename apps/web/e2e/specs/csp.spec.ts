import { expect, test, type Page } from "@playwright/test"

import { ACCOUNTS } from "../support/accounts"
import { login, testId } from "../support/auth"

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
      seen.push({ ...violation, source: `${step} | ${violation.source}` })
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

  // Academy com sessão (etapa 2.7b, S6): cabeçalho logado, curso, aula com o quiz aberto,
  // Meu progresso e o painel com o cartão da Academy.
  await page.route("https://www.youtube-nocookie.com/**", (route) =>
    route.fulfill({ contentType: "text/html", body: "<!doctype html><title>player</title>" }),
  )
  await page.goto("/academy")
  await expect(page.getByRole("link", { name: "Fundamentos E2E" })).toBeVisible()
  await visit("/academy (logado)")
  await page.goto("/academy/cursos/fundamentos-e2e")
  await expect(page.getByRole("heading", { level: 1 })).toBeVisible()
  await visit("curso (logado)")
  await page.goto("/academy/cursos/fundamentos-e2e/aulas/counter-strafe")
  const quiz = testId(page, "quiz-panel")
  await quiz.getByRole("button", { name: "Iniciar quiz" }).click()
  await expect(quiz.getByRole("button", { name: "Enviar respostas" })).toBeVisible()
  await visit("aula com quiz (logado)")
  await page.goto("/painel/academy")
  await expect(page.getByRole("heading", { level: 1, name: "Meu progresso" })).toBeVisible()
  await visit("/painel/academy")
  await page.goto("/painel")
  await expect(page.getByRole("region", { name: "Academy", exact: true })).toBeVisible()
  await visit("/painel (cartão)")

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
  const perStep = new Map<string, number>()
  for (const violation of reportOnly) {
    const step = violation.source.split(" | ")[0] ?? ""
    perStep.set(step, (perStep.get(step) ?? 0) + 1)
  }
  console.log(`CSP Report-Only por página: ${JSON.stringify(Object.fromEntries(perStep))}`)

  expect(enforced).toEqual([])
})
