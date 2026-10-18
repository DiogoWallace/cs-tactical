import { expect, test, type Page } from "@playwright/test"

import { ACCOUNTS } from "../support/accounts"
import { expectNoSeriousA11yViolations } from "../support/a11y"
import { login } from "../support/auth"

/**
 * Learning (etapa 2.4) contra a API real da stack E2E, com o curso de e2e/fixtures/academy:
 * "Fundamentos E2E" tem 3 aulas públicas (counter-strafe, peek-seguro, posicionamento).
 * Cada teste usa a própria conta (support/accounts.ts), criada pelo `e2e:prepare`.
 */
const COURSE = "/academy/cursos/fundamentos-e2e"
const FIRST_LESSON = `${COURSE}/aulas/counter-strafe`

/** O player do YouTube nunca é carregado de verdade: os testes não dependem da internet. */
async function stubYoutube(page: Page) {
  await page.route("https://www.youtube-nocookie.com/**", (route) =>
    route.fulfill({ contentType: "text/html", body: "<!doctype html><title>player</title>" }),
  )
}

function progressPanel(page: Page) {
  return page.getByRole("region", { name: "Seu progresso" })
}

test.describe("Learning: visitante", () => {
  test("lê o curso e a aula, com convite para entrar no lugar das ações", async ({ page }) => {
    await stubYoutube(page)
    await page.goto(COURSE)

    const panel = progressPanel(page)
    await expect(panel.getByRole("link", { name: "Entrar para se matricular" })).toHaveAttribute(
      "href",
      `/login?next=${encodeURIComponent(COURSE)}`,
    )
    await expect(page.getByRole("button", { name: "Matricular-se no curso" })).toHaveCount(0)

    await page.goto(FIRST_LESSON)
    const completion = page.getByRole("region", { name: "Conclusão da aula" })
    await expect(completion.getByRole("link", { name: "Entrar" })).toHaveAttribute(
      "href",
      `/login?next=${encodeURIComponent(FIRST_LESSON)}`,
    )
    await expect(page.getByRole("button", { name: "Marcar aula como concluída" })).toHaveCount(0)
  })
})

test.describe("Learning: jogador", () => {
  test("matricula, estuda, conclui e encontra o progresso depois de entrar de novo", async ({
    page,
  }) => {
    await stubYoutube(page)
    await login(page, ACCOUNTS.learning)

    // 1. Curso publicado, ainda sem matrícula.
    await page.goto(COURSE)
    const panel = progressPanel(page)
    await panel.getByRole("button", { name: "Matricular-se no curso" }).click()

    // 2. Matrícula: progresso real (0 de 3) e "continuar" na primeira aula.
    await expect(panel.getByRole("progressbar", { name: "Progresso do curso" })).toHaveAttribute(
      "aria-valuetext",
      "0%, 0 de 3 aulas concluídas",
    )
    await panel.getByRole("link", { name: /Continuar: Counter-strafe/ }).click()

    // 3. Aula: concluir atualiza a página sem recarregar e anuncia o progresso.
    await expect(page).toHaveURL(FIRST_LESSON)
    const completion = page.getByRole("region", { name: "Conclusão da aula" })
    await completion.getByRole("button", { name: "Marcar aula como concluída" }).click()
    await expect(completion.getByText("Aula concluída", { exact: true })).toBeVisible()
    await expect(completion.getByRole("progressbar")).toHaveAttribute(
      "aria-valuetext",
      "33,33%, 1 de 3 aulas concluídas",
    )
    await expect(page.getByText("Aula concluída. Progresso do curso: 33,33%.")).toBeAttached()
    const sidebar = page.getByRole("complementary", { name: "Conteúdo do curso" })
    await expect(
      sidebar.getByRole("link", { name: /Counter-strafe/ }).getByText("Concluída"),
    ).toBeAttached()

    // 4. Sair e entrar de novo: o progresso continua no servidor.
    await page.goto("/painel")
    await page.getByRole("button", { name: "Sair" }).click()
    await expect(page).toHaveURL("/login")
    await login(page, ACCOUNTS.learning)

    await page
      .getByRole("navigation", { name: "Principal" })
      .getByRole("link", { name: "Meu progresso" })
      .first()
      .click()
    await expect(page).toHaveURL("/painel/academy")
    const myCourses = page.getByRole("region", { name: "Meus cursos" })
    await expect(myCourses.getByRole("link", { name: "Fundamentos E2E" })).toBeVisible()
    await expect(myCourses.getByRole("progressbar")).toHaveAttribute(
      "aria-valuetext",
      "33,33%, 1 de 3 aulas concluídas",
    )
    await expect(myCourses.getByRole("link", { name: /Continuar: Peek seguro/ })).toBeVisible()

    await page.goto(COURSE)
    await expect(progressPanel(page).getByRole("progressbar")).toHaveAttribute(
      "aria-valuetext",
      "33,33%, 1 de 3 aulas concluídas",
    )
    await expect(page.getByTestId("lesson-completed")).toHaveCount(1)

    // O catálogo marca o curso em que o jogador está matriculado.
    await page.goto("/academy")
    await expect(
      page
        .getByRole("article")
        .filter({ hasText: "Fundamentos E2E" })
        .getByText("Matriculado · 33,33%"),
    ).toBeVisible()
  })
})

const THEMES = ["dark", "light"] as const

test("telas do Learning sem violações sérias de acessibilidade, nos dois temas", async ({
  page,
}) => {
  await stubYoutube(page)
  await login(page, ACCOUNTS.learningA11y)

  for (const theme of THEMES) {
    await page.evaluate((value) => window.localStorage.setItem("theme", value), theme)

    await page.goto(COURSE)
    await expect(page.locator("html")).toHaveClass(new RegExp(`\\b${theme}\\b`))
    // O painel chega por streaming (Suspense): espera o estado definitivo, matrícula pendente
    // (primeiro tema) ou progresso (segundo tema, já matriculado), antes de decidir.
    const panel = progressPanel(page)
    const enroll = panel.getByRole("button", { name: "Matricular-se no curso" })
    const progress = panel.getByRole("progressbar")
    await expect(enroll.or(progress)).toBeVisible()
    if (await enroll.isVisible()) {
      await enroll.click()
    }
    await expect(progress).toBeVisible()
    await expectNoSeriousA11yViolations(page, `learning-curso-${theme}`)

    await page.goto(FIRST_LESSON)
    await expect(page.getByRole("region", { name: "Conclusão da aula" })).toBeVisible()
    await expectNoSeriousA11yViolations(page, `learning-aula-${theme}`)

    await page.goto("/painel/academy")
    await expect(page.getByRole("heading", { level: 1, name: "Meu progresso" })).toBeVisible()
    await expectNoSeriousA11yViolations(page, `learning-meu-progresso-${theme}`)
  }
})
