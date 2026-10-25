import AxeBuilder from "@axe-core/playwright"
import { expect, test, type Page } from "@playwright/test"

const BLOCKING_IMPACTS = new Set(["serious", "critical"])

/**
 * Espera as transições e animações CSS finitas terminarem antes de medir. Os botões têm
 * `transition-all` (com `disabled:opacity-50`), e o axe chegou a medir o contraste no meio de uma
 * troca de cor ou de opacidade: falhas intermitentes de `color-contrast` no tema claro (CI dos PRs
 * #38 e #43). Animações infinitas (esqueletos, ícones girando) nunca terminam e não entram.
 */
export async function waitForSettledStyles(page: Page) {
  await page.waitForFunction(
    () =>
      document
        .getAnimations()
        .every(
          (animation) =>
            animation.playState !== "running" ||
            animation.effect?.getComputedTiming().iterations === Infinity,
        ),
    undefined,
    { timeout: 10_000 },
  )
}

type Options = {
  /** Regras desligadas nesta análise. Use só com motivo documentado no teste que chama. */
  disableRules?: string[]
}

/**
 * Roda o axe na página atual (WCAG 2.1 A e AA) e falha em violações sérias ou críticas.
 * Todas as violações, inclusive as menores, vão para o relatório como anexo.
 */
export async function expectNoSeriousA11yViolations(
  page: Page,
  name: string,
  { disableRules = [] }: Options = {},
) {
  await waitForSettledStyles(page)
  const results = await new AxeBuilder({ page })
    .withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa"])
    .disableRules(disableRules)
    .analyze()

  await test.info().attach(`axe-${name}.json`, {
    body: JSON.stringify(results.violations, null, 2),
    contentType: "application/json",
  })

  const blocking = results.violations
    .filter((violation) => BLOCKING_IMPACTS.has(violation.impact ?? ""))
    .map((violation) => ({
      id: violation.id,
      impact: violation.impact,
      help: violation.help,
      targets: violation.nodes.map((node) => node.target.join(" ")),
    }))

  expect(blocking, `violações sérias/críticas de acessibilidade em ${name}`).toEqual([])
}
