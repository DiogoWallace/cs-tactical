import { expect, test } from "@playwright/test"

import { ACCOUNTS } from "../support/accounts"
import { login, submitLogin, testId, text } from "../support/auth"

const DAY_IN_SECONDS = 24 * 60 * 60

test.describe("login", () => {
  test("senha errada mostra a mensagem genérica e não cria sessão", async ({ page }) => {
    await submitLogin(page, ACCOUNTS.login, { password: "senha-errada-123" })

    await expect(page.getByText("E-mail ou senha incorretos.")).toBeVisible()
    await expect(page).toHaveURL("/login")
  })

  test("entra, mostra o painel com a Academy e o módulo futuro só como informação", async ({
    page,
  }) => {
    await login(page, ACCOUNTS.login)

    const account = text(page, ACCOUNTS.login.email).first()
    await expect(account).toBeVisible()
    await expect(text(page, "Verificado", { exact: true })).toBeVisible()

    // A Academy já existe (etapa 2.6): cartão real, com o convite para explorar os cursos.
    const academy = page.getByRole("region", { name: "Academy" })
    await expect(academy.getByRole("link", { name: "Explorar cursos" })).toHaveAttribute(
      "href",
      "/academy",
    )
    await expect(page.getByTestId("upcoming-academy")).toHaveCount(0)

    // Teams ainda não existe: só informativo, nenhum link ou botão.
    const teams = testId(page, "upcoming-teams")
    await expect(teams).toContainText("Em breve")
    await expect(teams.getByRole("link")).toHaveCount(0)
    await expect(teams.getByRole("button")).toHaveCount(0)

    await page.getByRole("link", { name: "Editar perfil, e-mail e senha" }).click()
    await expect(page).toHaveURL("/painel/conta")
    await expect(page.getByRole("heading", { level: 1, name: "Conta" })).toBeVisible()
  })

  test("quem já está logado e abre o login vai direto ao painel", async ({ page }) => {
    await login(page, ACCOUNTS.login)

    await page.goto("/login")
    await expect(page).toHaveURL("/painel")
    await page.goto("/cadastro")
    await expect(page).toHaveURL("/painel")
  })

  test("sem 'manter conectado' não há cookie de lembrar", async ({ page, context }) => {
    await login(page, ACCOUNTS.remember)

    const cookies = await context.cookies()
    expect(cookies.some((cookie) => cookie.name.startsWith("remember_web_"))).toBe(false)
  })

  test("'manter conectado' cria um cookie de lembrar de 30 dias", async ({ page, context }) => {
    await login(page, ACCOUNTS.remember, { remember: true })

    const remember = (await context.cookies()).find((cookie) =>
      cookie.name.startsWith("remember_web_"),
    )
    expect(remember, "cookie remember_web_*").toBeDefined()
    expect(remember!.httpOnly).toBe(true)
    const days = (remember!.expires - Date.now() / 1000) / DAY_IN_SECONDS
    expect(days).toBeGreaterThan(29)
    expect(days).toBeLessThanOrEqual(30)
  })
})
