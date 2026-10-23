import { expect, test, type Page } from "@playwright/test"

import { ACCOUNTS } from "../support/accounts"
import { expectNoSeriousA11yViolations } from "../support/a11y"
import { login, text } from "../support/auth"

/**
 * Quizzes (etapa 2.5) contra a API real da stack E2E. O quiz da aula "counter-strafe" de
 * e2e/fixtures/academy tem 2 tentativas, nota mínima de 80% e duas perguntas:
 * escolha única (OPCAO-ERRADA-E2E / OPCAO-CERTA-E2E, explicação EXPLICACAO-SECRETA-E2E) e
 * múltipla ("No peek" certa, "Na rotação" errada). Cada teste usa a própria conta.
 */
const LESSON = "/academy/cursos/fundamentos-e2e/aulas/counter-strafe"
const EXPLANATION = "EXPLICACAO-SECRETA-E2E"

async function stubYoutube(page: Page) {
  await page.route("https://www.youtube-nocookie.com/**", (route) =>
    route.fulfill({ contentType: "text/html", body: "<!doctype html><title>player</title>" }),
  )
}

function quiz(page: Page) {
  return page.getByTestId("quiz-panel")
}

/** Responde à tentativa aberta: `right` marca o gabarito; senão, as erradas. */
async function answer(page: Page, right: boolean) {
  const panel = quiz(page)
  await panel.getByRole("radio", { name: right ? "OPCAO-CERTA-E2E" : "OPCAO-ERRADA-E2E" }).check()
  await panel.getByRole("checkbox", { name: right ? "No peek" : "Na rotação" }).check()
  await panel.getByRole("button", { name: "Enviar respostas" }).click()
}

test("reprova sem ver o gabarito, aprova na segunda tentativa e só então vê a revisão", async ({
  page,
}) => {
  await stubYoutube(page)
  await login(page, ACCOUNTS.quiz)
  await page.goto(LESSON)

  const panel = quiz(page)
  await expect(panel.getByText("2 tentativas restantes")).toBeVisible()
  await panel.getByRole("button", { name: "Iniciar quiz" }).click()
  await expect(panel.getByText("Tentativa 1 de 2")).toBeVisible()
  await expect(panel.getByRole("group")).toHaveCount(2)
  expect(await page.content()).not.toContain(EXPLANATION)

  // 1ª tentativa errada: nota e acerto por pergunta, sem gabarito nem explicação.
  await answer(page, false)
  await expect(panel.getByRole("heading", { name: "Resultado da tentativa 1" })).toBeVisible()
  await expect(text(panel, "0%", { exact: true })).toBeVisible()
  await expect(text(panel, "Não aprovado", { exact: true })).toBeVisible()
  await expect(panel.getByText("Correta", { exact: true })).toHaveCount(0)
  expect(await page.content()).not.toContain(EXPLANATION)
  await expect(page.getByText("Você acertou 0 de 2 (0%). Não aprovado.")).toBeAttached()

  // 2ª tentativa certa: aprovado, e a revisão é liberada na mesma resposta.
  await panel.getByRole("button", { name: "Tentar de novo" }).click()
  await expect(panel.getByText("Tentativa 2 de 2")).toBeVisible()
  await answer(page, true)
  await expect(panel.getByRole("heading", { name: "Resultado da tentativa 2" })).toBeVisible()
  await expect(text(panel, "100%", { exact: true })).toBeVisible()
  await expect(text(panel, "Aprovado", { exact: true })).toBeVisible()
  await expect(text(panel, EXPLANATION)).toBeVisible()
  await expect(panel.getByText("Correta", { exact: true })).toHaveCount(2)

  // Recarregar: a aprovação veio do servidor e não há tentativa nova (R16a).
  await page.reload()
  await expect(text(quiz(page), "Você foi aprovado neste quiz com 100%.")).toBeVisible()
  await expect(
    quiz(page).getByRole("button", { name: /Iniciar|Tentar de novo|Continuar/ }),
  ).toHaveCount(0)
})

const THEMES = ["dark", "light"] as const

test("quiz sem violações sérias de acessibilidade, respondendo e no resultado, nos dois temas", async ({
  page,
}) => {
  await stubYoutube(page)
  await login(page, ACCOUNTS.quizA11y)

  for (const theme of THEMES) {
    await page.evaluate((value) => window.localStorage.setItem("theme", value), theme)
    await page.goto(LESSON)
    await expect(page.locator("html")).toHaveClass(new RegExp(`\\b${theme}\\b`))

    const panel = quiz(page)
    // Primeiro tema: nenhuma tentativa ainda; segundo: a primeira foi reprovada.
    await panel.getByRole("button", { name: /Iniciar quiz|Tentar de novo/ }).click()
    await expect(panel.getByRole("button", { name: "Enviar respostas" })).toBeVisible()
    await expectNoSeriousA11yViolations(page, `quiz-respondendo-${theme}`)

    await answer(page, false)
    await expect(panel.getByRole("heading", { name: /Resultado da tentativa/ })).toBeVisible()
    await expectNoSeriousA11yViolations(page, `quiz-resultado-${theme}`)
  }

  // As duas tentativas acabaram: a revisão com o gabarito foi liberada (R20, Q1), sem tentativa nova.
  const panel = quiz(page)
  await expect(text(panel, EXPLANATION)).toBeVisible()
  await expect(panel.getByText("Correta", { exact: true })).toHaveCount(2)
  await expect(text(panel, "0 tentativas restantes", { exact: false })).toBeVisible()
  await expect(panel.getByRole("button", { name: "Tentar de novo" })).toHaveCount(0)

  // Recarregar: o esgotamento vem do servidor; só a revisão continua disponível (R16).
  await page.reload()
  await expect(
    text(quiz(page), "Suas tentativas acabaram. A revisão com o gabarito está liberada."),
  ).toBeVisible()
  await expect(quiz(page).getByRole("button", { name: "Ver revisão" })).toBeVisible()
  await expect(
    quiz(page).getByRole("button", { name: /Iniciar|Tentar de novo|Continuar/ }),
  ).toHaveCount(0)
})
