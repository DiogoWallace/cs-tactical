import { expect, test } from "@playwright/test"

import { ACCOUNTS, E2E_PASSWORD } from "../support/accounts"
import { field, login, SESSION_COOKIE, sessionCookie } from "../support/auth"

test.describe("sessão e proteção de rotas", () => {
  test("sem sessão, a área logada leva ao login guardando o destino", async ({ page }) => {
    await page.goto("/painel/conta?aba=senha")

    await expect(page).toHaveURL(`/login?next=${encodeURIComponent("/painel/conta?aba=senha")}`)
  })

  test("cookie de sessão inválido também leva ao login", async ({ page, context, baseURL }) => {
    await context.addCookies([
      { name: SESSION_COOKIE, value: "sessao-inventada", url: baseURL!, httpOnly: true },
    ])

    await page.goto("/painel")

    await expect(page).toHaveURL(`/login?next=${encodeURIComponent("/painel")}`)
    await expect(page.getByRole("button", { name: "Entrar" })).toBeVisible()
  })

  test("depois de entrar, volta ao destino guardado com a query", async ({ page }) => {
    await page.goto("/painel/conta?aba=senha")
    await expect(page).toHaveURL(/\/login\?next=/)

    await field(page, "E-mail").fill(ACCOUNTS.guard.email)
    await field(page, "Senha").fill(E2E_PASSWORD)
    await page.getByRole("button", { name: "Entrar" }).click()

    await expect(page).toHaveURL("/painel/conta?aba=senha")
    await expect(page.getByRole("heading", { level: 1, name: "Conta" })).toBeVisible()
  })

  test("caminho inexistente na área logada mostra o 404 com o cabeçalho", async ({ page }) => {
    await login(page, ACCOUNTS.guard)

    await page.goto("/painel/nao-existe")

    await expect(page.getByText("Página não encontrada")).toBeVisible()
    await expect(page.getByRole("navigation", { name: "Principal" })).toBeVisible()
    await page.getByRole("link", { name: "Voltar ao painel" }).click()
    await expect(page).toHaveURL("/painel")
  })

  test("sair encerra a sessão: voltar no navegador não reabre a área logada", async ({
    page,
    context,
    browser,
    baseURL,
  }) => {
    await login(page, ACCOUNTS.session)
    const oldSession = await sessionCookie(context)
    expect(oldSession).toBeDefined()

    await page.getByRole("button", { name: "Sair" }).click()
    await expect(page).toHaveURL("/login")

    await page.goBack()
    await expect(page).toHaveURL(`/login?next=${encodeURIComponent("/painel")}`)

    // O cookie antigo, mesmo reaproveitado em outro navegador, não vale mais.
    const other = await browser.newContext({ baseURL })
    await other.addCookies([
      { name: SESSION_COOKIE, value: oldSession!.value, url: baseURL!, httpOnly: true },
    ])
    const otherPage = await other.newPage()
    await otherPage.goto("/painel")
    await expect(otherPage).toHaveURL(`/login?next=${encodeURIComponent("/painel")}`)
    await other.close()
  })
})
