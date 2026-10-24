import { expect, test, type Page } from "@playwright/test"

import { expectNoSeriousA11yViolations } from "../support/a11y"
import { ACCOUNTS } from "../support/accounts"
import { login, testId } from "../support/auth"

/**
 * Painel e navegação entre a área logada e a Academy (etapa 2.6), contra a API real da stack E2E.
 * O curso "Fundamentos E2E" (e2e/fixtures/academy) tem 3 aulas públicas. Cada teste usa a própria
 * conta; só o do painel com progresso grava dados (matrícula e conclusões).
 *
 * Locators por papel ignoram a cópia escondida de páginas já visitadas (Next 16, `<Activity>`), então
 * as verificações de ausência ("Entrar" sumiu do cabeçalho) valem para o que o usuário vê.
 */
const COURSE = "/academy/cursos/fundamentos-e2e"
const LESSONS = [
  `${COURSE}/aulas/counter-strafe`,
  `${COURSE}/aulas/peek-seguro`,
  `${COURSE}/aulas/posicionamento`,
] as const

/** O player do YouTube nunca é carregado de verdade: os testes não dependem da internet. */
async function stubYoutube(page: Page) {
  await page.route("https://www.youtube-nocookie.com/**", (route) =>
    route.fulfill({ contentType: "text/html", body: "<!doctype html><title>player</title>" }),
  )
}

/**
 * Erros de hidratação do React e exceções não tratadas na página. No build de produção o React
 * reporta a hidratação inconsistente com os códigos minificados (#418, #423, #425...).
 */
function watchHydration(page: Page): string[] {
  const errors: string[] = []
  page.on("pageerror", (error) => errors.push(error.message))
  page.on("console", (message) => {
    if (message.type() === "error" && /hydrat|#41[89]|#42[1-5]/i.test(message.text())) {
      errors.push(message.text())
    }
  })
  return errors
}

function header(page: Page) {
  return page.getByRole("banner")
}

function mainNav(page: Page) {
  return header(page).getByRole("navigation", { name: "Principal" })
}

/** Cabeçalho de quem está logado: navegação completa e nenhum convite para entrar. */
async function expectSignedInHeader(page: Page, nickname: string) {
  await expect(mainNav(page).getByRole("link", { name: "Meu progresso" })).toBeVisible()
  await expect(header(page).getByText(nickname)).toBeVisible()
  await expect(header(page).getByRole("link", { name: "Entrar" })).toHaveCount(0)
  await expect(header(page).getByRole("link", { name: "Criar conta" })).toHaveCount(0)
}

function academyCard(page: Page) {
  return page.getByRole("region", { name: "Academy" })
}

/** Os três números do cartão, na ordem: matriculados, concluídos, aulas concluídas. */
async function cardStats(page: Page) {
  const terms = academyCard(page).getByRole("term")
  const definitions = academyCard(page).getByRole("definition")
  return { labels: await terms.allTextContents(), values: await definitions.allTextContents() }
}

test.describe("navegação: visitante", () => {
  test("explora a Academy com Entrar e Criar conta no cabeçalho", async ({ page }) => {
    await stubYoutube(page)
    const hydrationErrors = watchHydration(page)
    await page.goto("/academy")

    await expect(mainNav(page).getByRole("link", { name: "Academy" })).toHaveAttribute(
      "aria-current",
      "page",
    )
    await expect(header(page).getByRole("link", { name: "Entrar" })).toBeVisible()
    await expect(header(page).getByRole("link", { name: "Criar conta" })).toBeVisible()
    await expect(header(page).getByRole("button", { name: "Sair" })).toHaveCount(0)
    await expect(mainNav(page).getByRole("link", { name: "Painel" })).toHaveCount(0)

    await page.getByRole("link", { name: "Fundamentos E2E" }).click()
    await expect(page).toHaveURL(COURSE)
    await expect(header(page).getByRole("link", { name: "Entrar" })).toBeVisible()
    expect(hydrationErrors).toEqual([])
  })
})

test.describe("navegação: jogador", () => {
  test("vai do painel à Academy, ao progresso e de volta, sem o cabeçalho de visitante", async ({
    page,
  }) => {
    await stubYoutube(page)
    const hydrationErrors = watchHydration(page)
    const account = ACCOUNTS.navigation
    await login(page, account)

    // Painel sem matrícula: convite para explorar; Teams continua como "Em breve".
    const card = academyCard(page)
    await expect(card.getByText("Você ainda não está em nenhum curso")).toBeVisible()
    await expect(card.getByRole("link", { name: "Continuar estudando" })).toHaveCount(0)
    await expect(testId(page, "upcoming-teams")).toContainText("Em breve")

    // Painel → Academy: o catálogo público, com a navegação da sessão.
    await card.getByRole("link", { name: "Explorar cursos" }).click()
    await expect(page).toHaveURL("/academy")
    await expect(page.getByRole("heading", { level: 1 })).toContainText("Aprenda CS2")
    await expectSignedInHeader(page, account.nickname)
    await expect(mainNav(page).getByRole("link", { name: "Academy" })).toHaveAttribute(
      "aria-current",
      "page",
    )

    // Academy → curso → aula: o cabeçalho não volta a pedir login.
    await page.getByRole("link", { name: "Fundamentos E2E" }).click()
    await expect(page).toHaveURL(COURSE)
    await expectSignedInHeader(page, account.nickname)
    await page.getByRole("link", { name: "Começar pela primeira aula" }).click()
    await expect(page).toHaveURL(LESSONS[0])
    await expectSignedInHeader(page, account.nickname)
    await expect(mainNav(page).getByRole("link", { name: "Academy" })).toHaveAttribute(
      "aria-current",
      "page",
    )

    // Aula → Meu progresso (pelo cabeçalho).
    await mainNav(page).getByRole("link", { name: "Meu progresso" }).click()
    await expect(page).toHaveURL("/painel/academy")
    await expect(page.getByRole("heading", { level: 1, name: "Meu progresso" })).toBeVisible()
    await expect(mainNav(page).getByRole("link", { name: "Meu progresso" })).toHaveAttribute(
      "aria-current",
      "page",
    )

    // De volta ao painel.
    await mainNav(page).getByRole("link", { name: "Painel" }).click()
    await expect(page).toHaveURL("/painel")
    await expect(
      page.getByRole("heading", { level: 1, name: `Olá, ${account.nickname}` }),
    ).toBeVisible()

    // Sair numa página pública: o cabeçalho volta a ser o de visitante.
    await mainNav(page).getByRole("link", { name: "Academy" }).click()
    await expect(page).toHaveURL("/academy")
    await header(page).getByRole("button", { name: "Sair" }).click()
    await expect(page).toHaveURL("/login")
    await page.goto("/academy")
    await expect(header(page).getByRole("link", { name: "Entrar" })).toBeVisible()
    await expect(header(page).getByRole("button", { name: "Sair" })).toHaveCount(0)
    await expect(header(page).getByText(account.nickname)).toHaveCount(0)
    expect(hydrationErrors).toEqual([])
  })

  test("o painel mostra o curso em andamento e, depois, o curso concluído", async ({ page }) => {
    await stubYoutube(page)
    await login(page, ACCOUNTS.dashboard)

    // Matrícula e primeira aula concluída.
    await page.goto(COURSE)
    const panel = page.getByRole("region", { name: "Seu progresso" })
    await panel.getByRole("button", { name: "Matricular-se no curso" }).click()
    await panel.getByRole("link", { name: /Continuar: Counter-strafe/ }).click()
    await expect(page).toHaveURL(LESSONS[0])
    await completeLesson(page)

    await mainNav(page).getByRole("link", { name: "Painel" }).click()
    await expect(page).toHaveURL("/painel")
    const card = academyCard(page)
    await expect(card.getByText("Continue de onde parou")).toBeVisible()
    expect(await cardStats(page)).toEqual({
      labels: ["Curso matriculado", "Cursos concluídos", "Aula concluída"],
      values: ["1", "0", "1"],
    })
    await expect(card.getByText("Fundamentos E2E")).toBeVisible()
    await expect(card.getByText("Próxima aula: Peek seguro")).toBeVisible()
    await expect(card.getByRole("progressbar", { name: "Progresso do curso" })).toHaveAttribute(
      "aria-valuetext",
      "33,33%, 1 de 3 aulas concluídas",
    )

    // "Continuar estudando" leva à próxima aula.
    await card.getByRole("link", { name: "Continuar estudando" }).click()
    await expect(page).toHaveURL(LESSONS[1])
    await completeLesson(page)
    await page.goto(LESSONS[2])
    await completeLesson(page)

    // Curso concluído: em dia, sem "Continuar estudando".
    await page.goto("/painel")
    await expect(
      card.getByText("Você está em dia: nenhuma aula pendente nos seus cursos."),
    ).toBeVisible()
    expect(await cardStats(page)).toEqual({
      labels: ["Curso matriculado", "Curso concluído", "Aulas concluídas"],
      values: ["1", "1", "3"],
    })
    await expect(card.getByRole("link", { name: "Continuar estudando" })).toHaveCount(0)
    await card.getByRole("link", { name: "Ver meu progresso" }).click()
    await expect(page).toHaveURL("/painel/academy")
    await expect(page.getByText(/Concluído em/).filter({ visible: true })).toBeVisible()
  })
})

async function completeLesson(page: Page) {
  const completion = page.getByRole("region", { name: "Conclusão da aula" })
  await completion.getByRole("button", { name: "Marcar aula como concluída" }).click()
  await expect(completion.getByText("Aula concluída", { exact: true })).toBeVisible()
}

// Roda só no projeto chromium-mobile (tag @mobile).
test("menu do celular leva à Academy e ao progresso, sem Entrar no cabeçalho @mobile", async ({
  page,
}) => {
  const account = ACCOUNTS.navigationMobile
  await login(page, account)

  const openMenu = page.getByRole("button", { name: "Abrir menu" })
  await openMenu.click()
  const menu = page.getByRole("dialog", { name: "Menu" })
  await menu.getByRole("link", { name: "Academy" }).click()
  await expect(page).toHaveURL("/academy")
  await expect(menu).toBeHidden()
  await expect(header(page).getByRole("link", { name: "Entrar" })).toHaveCount(0)

  // Na página pública, o mesmo menu da área logada.
  await openMenu.click()
  await expect(menu).toContainText(account.email)
  await expect(menu.getByRole("link", { name: "Academy" })).toHaveAttribute("aria-current", "page")
  await menu.getByRole("link", { name: "Meu progresso" }).click()
  await expect(page).toHaveURL("/painel/academy")
  await expect(page.getByRole("heading", { level: 1, name: "Meu progresso" })).toBeVisible()

  // Sem rolagem horizontal no celular.
  const overflow = await page.evaluate(
    () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
  )
  expect(overflow).toBeLessThanOrEqual(0)
})

for (const theme of ["dark", "light"] as const) {
  test(`painel e Academy com sessão, sem violações sérias de acessibilidade (tema ${theme})`, async ({
    page,
  }) => {
    await page.addInitScript((value) => window.localStorage.setItem("theme", value), theme)
    await login(page, ACCOUNTS.navigationA11y)

    await expect(academyCard(page).getByRole("link", { name: "Explorar cursos" })).toBeVisible()
    await expectNoSeriousA11yViolations(page, `painel-academy-${theme}`)

    await page.goto("/academy")
    await expectSignedInHeader(page, ACCOUNTS.navigationA11y.nickname)
    await expect(page.getByRole("link", { name: "Fundamentos E2E" })).toBeVisible()
    await expectNoSeriousA11yViolations(page, `academy-logado-${theme}`)
  })
}
