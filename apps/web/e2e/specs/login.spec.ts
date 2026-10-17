import { expect, test } from "@playwright/test"

import { ACCOUNTS } from "../support/accounts"
import { login, submitLogin, text } from "../support/auth"

const DAY_IN_SECONDS = 24 * 60 * 60

test.describe("login", () => {
  test("senha errada mostra a mensagem genérica e não cria sessão", async ({ page }) => {
    await submitLogin(page, ACCOUNTS.login, { password: "senha-errada-123" })

    await expect(page.getByText("E-mail ou senha incorretos.")).toBeVisible()
    await expect(page).toHaveURL("/login")
  })

  test("entra, mostra o painel e os módulos futuros só como informação", async ({ page }) => {
    await login(page, ACCOUNTS.login)

    const account = text(page, ACCOUNTS.login.email).first()
    await expect(account).toBeVisible()
    await expect(text(page, "Verificado", { exact: true })).toBeVisible()

    for (const area of ["academy", "teams"]) {
      const card = page.getByTestId(`upcoming-${area}`)
      await expect(card).toContainText("Em breve")
      // Só informativos: nenhum link ou botão para áreas que ainda não existem.
      await expect(card.getByRole("link")).toHaveCount(0)
      await expect(card.getByRole("button")).toHaveCount(0)
    }

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
