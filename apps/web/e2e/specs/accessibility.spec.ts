import { expect, test } from "@playwright/test"

import { expectNoSeriousA11yViolations } from "../support/a11y"
import { ACCOUNTS } from "../support/accounts"
import { login } from "../support/auth"

test.describe("acessibilidade (axe)", () => {
  test("login e cadastro", async ({ page }) => {
    await page.goto("/login")
    await expect(page.getByRole("button", { name: "Entrar" })).toBeVisible()
    await expectNoSeriousA11yViolations(page, "login")

    await page.goto("/cadastro")
    // A lista de países só aparece no navegador: espera para analisar a página completa.
    await expect(page.getByRole("option", { name: "Brasil" })).toBeAttached()
    await expectNoSeriousA11yViolations(page, "cadastro")
  })

  test("painel e conta", async ({ page }) => {
    // Conta não verificada: o painel inclui o aviso de confirmação.
    await login(page, ACCOUNTS.a11y)
    await expect(page.getByTestId("upcoming-teams")).toBeVisible()
    await expectNoSeriousA11yViolations(page, "painel")

    await page.goto("/painel/conta")
    await expect(page.getByRole("button", { name: "Salvar perfil" })).toBeVisible()
    await expectNoSeriousA11yViolations(page, "conta")
  })
})
